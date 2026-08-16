<?php

declare(strict_types=1);

use humhub\services\BootstrapService;
use yii\log\DbTarget;
use yii\log\FileTarget;

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

$loader = require '/opt/humhub/protected/vendor/autoload.php';
require '/opt/humhub/protected/vendor/yiisoft/yii2/Yii.php';
$loader->addClassMap([
    BootstrapService::class => '/opt/humhub/protected/humhub/services/BootstrapService.php',
]);

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

$bootstrap = new BootstrapService(false);
$bootstrap->setPaths(config: '/data/config');
$config = $bootstrap->getConfig('console');
$targets = $config['components']['log']['targets'] ?? null;
if (!is_array($targets)) {
    fail('Effective log target configuration is missing.');
}
foreach ([FileTarget::class, DbTarget::class] as $targetClass) {
    $logVars = $targets[$targetClass]['logVars'] ?? null;
    if ($logVars !== []) {
        fail(
            "{$targetClass} must have an empty logVars list; effective value is "
            . json_encode($logVars, JSON_THROW_ON_ERROR),
        );
    }
}

$probe = 'CLASS_ARCHIVE_LOG_CONTEXT_PROBE_' . bin2hex(random_bytes(12));
$_GET['class_archive_log_probe'] = $probe;
$_SERVER['HTTP_COOKIE'] = $probe;
$_SERVER['CLASS_ARCHIVE_LOG_PROBE_SECRET'] = $probe;

$application = new humhub\components\console\Application($config);
Yii::error('Controlled Class Archive logging-safety error', 'class-archive.security');
Yii::getLogger()->flush(true);

unset(
    $_GET['class_archive_log_probe'],
    $_SERVER['HTTP_COOKIE'],
    $_SERVER['CLASS_ARCHIVE_LOG_PROBE_SECRET'],
);

$sensitiveValues = [$probe];
foreach (
    [
        'CLASS_ARCHIVE_CLAIM_CODE_PEPPER',
        'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET',
        'HUMHUB_CONFIG__COMPONENTS__DB__PASSWORD',
    ] as $environmentName
) {
    $value = $_ENV[$environmentName] ?? getenv($environmentName);
    if (is_string($value) && strlen($value) >= 16) {
        $sensitiveValues[] = $value;
    }
}

$logPath = '/data/logs/app.log';
$fileLog = is_file($logPath) ? file_get_contents($logPath) : '';
if ($fileLog === false) {
    fail("Cannot read {$logPath}");
}
foreach ($sensitiveValues as $sensitiveValue) {
    if (str_contains($fileLog, $sensitiveValue)) {
        fail('A sensitive value was found in the application file log.');
    }
}

$logTable = $application->db->schema->getTableSchema('log', true);
if ($logTable !== null) {
    foreach ($sensitiveValues as $index => $sensitiveValue) {
        $parameter = ':value' . $index;
        $count = $application->db->createCommand(
            "SELECT COUNT(*) FROM {{%log}} WHERE message LIKE {$parameter} OR prefix LIKE {$parameter}",
            [$parameter => '%' . $sensitiveValue . '%'],
        )->queryScalar();
        if ((int) $count !== 0) {
            fail('A sensitive value was found in the database log target.');
        }
    }
}

fwrite(STDOUT, "Effective HumHub log targets suppress request context and the controlled error probe leaked no sensitive values.\n");
