<?php

declare(strict_types=1);

use yii\helpers\ReplaceArrayValue;
use yii\log\DbTarget;
use yii\log\FileTarget;

return [
    'components' => [
        'log' => [
            'targets' => [
                FileTarget::class => [
                    'logVars' => new ReplaceArrayValue([]),
                ],
                DbTarget::class => [
                    'logVars' => new ReplaceArrayValue([]),
                ],
            ],
        ],
    ],
];
