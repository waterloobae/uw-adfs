<?php

namespace WaterlooBae\UwAdfs\Facades;

use Illuminate\Support\Facades\Facade;

class UwAdfs extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'uw-adfs';
    }
}