<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Validation;

use Rasuvaeff\Yii3FilestorageWeb\Validation\FileRule;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(FileRule::class)]
final class FileRuleTest
{
    public function optionsDumpEveryConfiguredValue(): void
    {
        $rule = new FileRule(
            allowedMimeTypes: ['image/png'],
            maxBytes: 1024,
            skipOnEmpty: true,
            skipOnError: true,
        );

        Assert::same($rule->getOptions(), [
            'allowedMimeTypes' => ['image/png'],
            'maxBytes' => 1024,
            'skipOnEmpty' => true,
            'skipOnError' => true,
        ]);
    }
}
