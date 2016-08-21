<?php

/*
 * This file is part of the `src-run/src-silver-papillon` project
 *
 * (c) Rob Frawley 2nd <rmf@src.run>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace AppBundle\Component\Facebook\Transformer;

/**
 * Class MediaStatusTransformer.
 */
class MediaStatusTransformer implements TransformerInterface
{
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public static function transform($data, ...$parameters)
    {
        switch (isset($data['video_status']) ?: 'error') {
            case 'ready':
                return 200;

            case 'processing':
                return 300;

            case 'error':
                return 500;

            default:
                return 501;
        }
    }
}

/* EOF */
