<?php
namespace Module\Shopping\RenderStrategy\JsonRenderer;

use Poirot\Std\GeneratorWrapper;
use Poirot\Std\Struct\aDataOptionsTrim;


class CartRenderer
    extends aDataOptionsTrim
{
    protected $cart;


    // Setter:

    function setCart($value)
    {
        $this->cart = $value;
    }


    // Getter:

    function getCart()
    {
        if (! $this->cart )
            return null;


        $items = new GeneratorWrapper($this->cart['items'], function ($value, $key) {
            return new CartItemRenderer($value);
        });

        return [
            'uid'      => (string) $this->cart['uid'],
            'owner_id' => (string) $this->cart['owner_uid'],
            'datetime_updated' => [
                'datetime'  => $this->cart['datetime_updated'],
                'timestamp' => $this->cart['datetime_updated']->getTimestamp(),
            ],
            'items' => $items,
        ];
    }
}
