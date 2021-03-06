<?php

/*
 * This file is part of the `src-run/srw-client-silver-papillon` project.
 *
 * (c) Rob Frawley 2nd <rmf@src.run>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace AppBundle\Model;

use AppBundle\Component\Location\GeoIpLookupInterface;
use AppBundle\Entity\Category;
use AppBundle\Entity\Coupon;
use AppBundle\Entity\Product;
use AppBundle\Manager\ConfigurationManager;
use Doctrine\ORM\EntityManager;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\VarDumper\VarDumper;

class Cart implements \Serializable
{
    /**
     * @var string
     */
    const SESSION_KEY = 'app.cart';

    /**
     * @var float
     */
    const RATE_SHIPPING = 6.40;

    /**
     * @var float
     */
    const RATE_TAX_PERCENTAGE = 0.069;

    /**
     * @var string
     */
    const LIST_TAX_REGIONS = 'Connecticut';

    /**
     * @var Product[]
     */
    private $items = [];

    /**
     * @var Session
     */
    private $session;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @var Coupon
     */
    protected $coupon;

    /**
     * @var GeoIpLookupInterface
     */
    protected $locationLookup;

    /**
     * @param Session                 $session
     * @param EntityManager           $entityManager
     * @param ConfigurationManager    $configurationManager
     * @param GeoIpLookupInterface $locationLookup
     */
    public function __construct(Session $session, EntityManager $entityManager, ConfigurationManager $configurationManager, GeoIpLookupInterface $locationLookup)
    {
        $this->setSession($session);
        $this->setEntityManager($entityManager);
        $this->setConfigurationManager($configurationManager);
        $this->setLocationLookup($locationLookup);
    }

    public function toJson()
    {
        $items = array_map(function (Product $p) {
            return [
                'id' => $p->getId(),
            ];
        }, $this->items);

        return json_encode($items);
    }

    /**
     * @param Session                 $session
     * @param EntityManager           $entityManager
     * @param ConfigurationManager    $configurationManager
     * @param GeoIpLookupInterface $locationLookup
     *
     * @return mixed|static
     */
    public static function create(Session $session, EntityManager $entityManager, ConfigurationManager $configurationManager, GeoIpLookupInterface $locationLookup)
    {
        if ($session->has(static::SESSION_KEY)) {
            $instance = unserialize($session->get(static::SESSION_KEY));

            if ($instance instanceof self) {
                $instance->setSession($session);
                $instance->setEntityManager($entityManager);
                $instance->setConfigurationManager($configurationManager);
                $instance->setLocationLookup($locationLookup);
                $instance->initialize();

                return $instance;
            }
        }

        return new static($session, $entityManager, $configurationManager, $locationLookup);
    }

    /**
     * @param Session $session
     */
    public function setSession(Session $session)
    {
        $this->session = $session;
    }

    /**
     * @param EntityManager $entityManager
     */
    public function setEntityManager(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @param ConfigurationManager $configurationManager
     */
    public function setConfigurationManager(ConfigurationManager $configurationManager)
    {
        $this->configurationManager = $configurationManager;
    }

    /**
     * @param GeoIpLookupInterface $locationLookup
     */
    public function setLocationLookup(GeoIpLookupInterface $locationLookup)
    {
        $this->locationLookup = $locationLookup;
    }

    /**
     * init.
     */
    public function initialize()
    {
        array_map(function (Product $p) {
            try {
                if (($c = $this->entityManager->merge($p->getCategory())) instanceof Category) {
                    $p->setCategory($c);
                }
            } catch (\Exception $e) {
            }

            return $p;
        }, $this->items);
    }

    /**
     * saver.
     */
    public function save()
    {
        $this->uuid();
        $this->session->set(static::SESSION_KEY, serialize($this));
    }

    /**
     * @return mixed|\Ramsey\Uuid\UuidInterface
     */
    public function uuid()
    {
        $sessionIdKey = static::SESSION_KEY.'.id';

        if (!$this->session->has($sessionIdKey) || empty($uuid = $this->session->get($sessionIdKey))) {
            $this->session->set($sessionIdKey, $uuid = Uuid::uuid4()->toString());

            return $uuid;
        }

        return $uuid;
    }

    /**
     * clear.
     */
    public function clear()
    {
        $this->session->remove(static::SESSION_KEY.'.id');
        $this->session->remove(static::SESSION_KEY);
        $this->session->save();

        $this->items = [];
    }

    /**
     * @return \AppBundle\Entity\Product[]
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * @return \AppBundle\Entity\Product[]|CartGroup
     */
    public function getItemsGrouped()
    {
        $grouped = array_reduce($this->items, function ($carry, Product $p) {
            if (!isset($carry[$p->getId()])) {
                $carry[$p->getId()] = new CartGroup();
            }

            $carry[$p->getId()]->add($p);

            return $carry;
        }, []);

        uasort($grouped, function (CartGroup $a, CartGroup $b) {
            return $a->item()->getName() > $b->item()->getName();
        });

        return $grouped;
    }

    /**
     * @param array $items
     */
    public function set(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * @param Product $product
     */
    public function rmOne(Product $product)
    {
        $removed = false;

        $this->items = array_values(array_filter($this->items, function (Product $p) use ($product, &$removed) {
            if ($p->getName() === $product->getName() && !$removed) {
                $removed = true;

                return false;
            }

            return true;
        }));
    }

    /**
     * @param Product $product
     */
    public function rm(Product $product)
    {
        $this->items = array_values(array_filter($this->items, function (Product $p) use ($product) {
            return $p->getName() !== $product->getName();
        }));
    }

    /**
     * @param Product $product
     */
    public function add(Product $product)
    {
        $this->items[] = $product;
    }

    /**
     * @param Product $product
     *
     * @return bool
     */
    public function has(Product $product)
    {
        return $this->count($product) > 0;
    }

    /**
     * @param Product|null $product
     *
     * @return int
     */
    public function count(Product $product = null)
    {
        if (!$product) {
            return count($this->items);
        }

        return count(array_filter($this->items, function (Product $p) use ($product) {
            return $p->getName() === $product->getName();
        }));
    }

    /**
     * @return float
     */
    public function subTotal()
    {
        return array_reduce($this->items, function ($carry, Product $p) {
            return $carry + $p->getPrice();
        }, 0);
    }

    /**
     * @return float
     */
    public function tax()
    {
        $clientIpRegion = $this->locationLookup->lookupUsingClientIp()->getRegionName();
        $taxableRegions = explode(',', $this->configurationManager->value('taxable.states', self::LIST_TAX_REGIONS));

        if (!in_array($clientIpRegion, $taxableRegions) && $clientIpRegion !== null) {
            return 0.0;
        }

        $taxableRate = $this->configurationManager->value('rate.taxable', Product::RATE_TAX_PERCENTAGE);

        return array_reduce($this->items, function ($carry, Product $p) use ($taxableRate) {
            return $p->isTaxable() ? $carry + ($p->getPrice() * $p->getTaxableRate($taxableRate)) : $carry;
        }, 0);
    }

    /**
     * @return float
     */
    public function shipping()
    {
        $shippingRate = $this->configurationManager->value('rate.shipping', Product::RATE_SHIPPING_DEFAULT);

        return array_reduce($this->items, function ($carry, Product $p) use ($shippingRate) {
            return $carry + $p->getShippingRate($shippingRate);
        }, 0);
    }

    /**
     * @return float
     */
    public function total()
    {
        return $this->subTotal() + $this->tax() + $this->shipping() - $this->couponAmount();
    }

    /**
     * @return Coupon
     */
    public function coupon()
    {
        return $this->coupon;
    }

    public function removeCoupon()
    {
        $this->coupon = null;
        $this->save();
    }

    /**
     * @param string $couponCode
     *
     * @return bool
     */
    public function couponFromCode(string $couponCode): bool
    {
        try {
            $this->coupon = $this
                ->entityManager
                ->getRepository(Coupon::class)->findOneBy([
                    'code' => $couponCode,
                ]);
            $this->save();
        } catch (\Exception $e) {
            return false;
        }

        return $this->coupon !== null;
    }

    /**
     * @return bool
     */
    public function hasCouponStateless(): bool
    {
        return $this->coupon !== null;
    }

    /**
     * @return bool
     */
    public function hasCoupon(): bool
    {
        if (!$this->coupon instanceof Coupon) {
            return false;
        }

        if ($this->coupon->hasExpiration() && $this->coupon->getExpiration()->format('U') <= (new \DateTime())->format('U')) {
            return false;
        }

        if ($this->coupon->hasMinimumRequirement() && $this->subTotal() < $this->coupon->getMinimumRequirement()) {
            return false;
        }

        return true;
    }

    /**
     * @return float
     */
    public function couponAmount(): float
    {
        if (!$this->hasCoupon()) {
            return 0;
        }

        if ($this->coupon->hasDiscountDollars()) {
            return $this->coupon->getDiscountDollars();
        }

        $discount = $this->subTotal() * $this->coupon->getDiscountPercent() / 100;

        if ($this->coupon->hasMaximumValue() && $discount > $this->coupon->getMaximumValue()) {
            return $this->coupon->getMaximumValue();
        }

        return $discount;
    }

    public function couponErrorMessage(): string
    {
        if (!$this->coupon instanceof Coupon) {
            return '';
        }

        if ($this->coupon->hasExpiration() && $this->coupon->getExpiration()->format('U') <= (new \DateTime())->format('U')) {
            return sprintf('The entered coupon code "%s" expired on %s and is no longer valid.', $this->coupon->getCode(), $this->coupon->getExpiration()->format('n-j-Y'));
        }

        if ($this->coupon->hasMinimumRequirement() && $this->subTotal() < $this->coupon->getMinimumRequirement()) {
            return sprintf('The entered coupon code "%s" requires an order totaling $%01.2f or more to be valid.', $this->coupon->getCode(), $this->coupon->getMinimumRequirement());
        }

        return sprintf('The entered coupon code "%s" is invalid.', $this->coupon->getCode());
    }

    /**
     * @return string
     */
    public function serialize()
    {
        return igbinary_serialize([
            'items' => $this->items,
            'coupon' => $this->coupon,
        ]);
    }

    /**
     * @param string $serialized
     */
    public function unserialize($serialized)
    {
        $data = igbinary_unserialize($serialized);

        $this->items = $data['items'] ?? [];
        $this->coupon = $data['coupon'] ?? null;
    }
}
