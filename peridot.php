<?php

use Evenement\EventEmitterInterface;
use Peridot\Plugin\Prophecy\ProphecyPlugin;

return function(EventEmitterInterface $emitter) {
    $plugin = new ProphecyPlugin($emitter);
};