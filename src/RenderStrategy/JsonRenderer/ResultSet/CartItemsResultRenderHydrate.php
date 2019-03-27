<?php
namespace Module\Shopping\RenderStrategy\JsonRenderer\ResultSet;

use Poirot\Std\GeneratorWrapper;
use Poirot\Std\Struct\aDataOptionsTrim;

use Module\Shopping\RenderStrategy\JsonRenderer\CartItemRenderer;


class CartItemsResultRenderHydrate
    extends aDataOptionsTrim
{
    protected $items;


    // Setter:

    function setItems($value)
    {
        $this->items = $value;
    }


    // Getter:

    function getItems()
    {
        return new GeneratorWrapper($this->items, function ($value, $key) {
            return new CartItemRenderer($value);
        });
    }
}
