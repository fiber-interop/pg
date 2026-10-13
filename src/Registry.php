<?php

namespace async\pg;

/** Driver state. Adapters write $candidates directly from their files autoload (it may run before async\pg\register() is defined). */
final class Registry
{
    public static ?Driver $driver = null;
    /** @var array<string, callable(): Driver> */
    public static array $candidates = [];
}
