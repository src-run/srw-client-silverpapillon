<?php

/*
 * This file is part of the `src-run/srw-client-silver-papillon` project.
 *
 * (c) Rob Frawley 2nd <rmf@src.run>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace AppBundle\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Class BackendTest.
 */
class BackendTest extends WebTestCase
{
    /**
     * @dataProvider queryParametersProvider
     *
     * @param $queryParameters
     */
    public function testBackendPagesLoadCorrectly($queryParameters)
    {
        $client = $this->createAuthorizedClient();
        $client->request('GET', '/admin/?'.http_build_query($queryParameters));

        $this->assertTrue($client->getResponse()->isSuccessful());
    }

    /**
     * @return array
     */
    public function queryParametersProvider()
    {
        return [
            [
                ['action' => 'list', 'entity' => 'Category'],
            ],
            [
                ['action' => 'list', 'entity' => 'Category', 'page' => 2],
            ],
            [
                ['action' => 'search', 'entity' => 'Category', 'query' => 'cat'],
            ],
            [
                ['action' => 'show', 'entity' => 'Category', 'id' => 1],
            ],
            [
                ['action' => 'edit', 'entity' => 'Category', 'id' => 1],
            ],

            [
                ['action' => 'list', 'entity' => 'Product'],
            ],
            [
                ['action' => 'list', 'entity' => 'Product', 'page' => 2],
            ],
            [
                ['action' => 'search', 'entity' => 'Product', 'query' => 'lorem'],
            ],
            [
                ['action' => 'show', 'entity' => 'Product', 'id' => 1],
            ],
            [
                ['action' => 'edit', 'entity' => 'Product', 'id' => 1],
            ],
        ];
    }

    /**
     * @return \Symfony\Bundle\FrameworkBundle\Client
     */
    private function createAuthorizedClient()
    {
        $client = static::createClient();
        $container = $client->getContainer();

        $session = $container->get('session');
        /** @var $userManager \FOS\UserBundle\Doctrine\UserManager */
        $userManager = $container->get('fos_user.user_manager');
        /** @var $loginManager \FOS\UserBundle\Security\LoginManager */
        $loginManager = $container->get('fos_user.security.login_manager');
        $firewallName = $container->getParameter('fos_user.firewall_name');

        $user = $userManager->findUserBy(['username' => 'john.smith']);
        $loginManager->loginUser($firewallName, $user);

        // save the login token into the session and put it in a cookie
        $container->get('session')->set('_security_'.$firewallName,
            serialize($container->get('security.token_storage')->getToken()));
        $container->get('session')->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $client;
    }
}

/* EOF */
