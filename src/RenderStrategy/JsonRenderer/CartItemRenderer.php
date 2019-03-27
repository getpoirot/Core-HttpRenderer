<?php
namespace Module\Shopping\RenderStrategy\JsonRenderer;

use Poirot\Std\Struct\DataOptionsOpen;


class CartItemRenderer
    extends DataOptionsOpen
{
    protected $uid;
    /** @var \DateTime */
    protected $datetimeCreated;


    // Setters:

    function setUid($uid)
    {
        $this->uid = $uid;
    }

    function setDatetimeCreated($dateTime)
    {
        $this->datetimeCreated = $dateTime;
    }


    // Getters:

    /**
     * Get Date Time Created
     *
     * @return array
     */
    function getDatetimeCreated()
    {
        return [
            'datetime'  => $this->datetimeCreated,
            'timestamp' => $this->datetimeCreated->getTimestamp(),
        ];
    }

    /**
     * @return string
     */
    function getUid()
    {
        return (string) $this->uid;
    }
}
