<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../scripts/validate_env.php';

class DeploymentEnvironmentValidatorTest extends TestCase
{
    #[Test]
    public function it_repairs_the_file_with_a_backup_without_logging_the_secret(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'almax-env-test-');
        $this->assertNotFalse($path);

        $malformedSecret = str_repeat('1', 32).'   '.str_repeat('2', 32);
        $compactSecret = str_repeat('1', 32).str_repeat('2', 32);
        $original = "APP_ENV=production\nWEBHOOK_SECRET={$malformedSecret}\n";
        file_put_contents($path, $original);

        try {
            ob_start();
            $status = \DeploymentEnvironmentValidator::run(['validate_env.php', $path]);
            $output = (string) ob_get_clean();

            $this->assertSame(0, $status);
            $this->assertSame($original, file_get_contents($path.'.backup'));
            $this->assertStringContainsString("WEBHOOK_SECRET={$compactSecret}", (string) file_get_contents($path));
            $this->assertStringNotContainsString($malformedSecret, $output);
            $this->assertStringNotContainsString($compactSecret, $output);
        } finally {
            @unlink($path);
            @unlink($path.'.backup');
        }
    }

    #[Test]
    public function it_repairs_only_a_spaced_64_character_hexadecimal_secret(): void
    {
        $secret = str_repeat('a', 32).'   '.str_repeat('b', 32);

        [$contents, $repairs] = \DeploymentEnvironmentValidator::repairSpacedHexSecrets(
            "APP_ENV=production\nWEBHOOK_SECRET={$secret}\n",
        );

        $this->assertSame(
            "APP_ENV=production\nWEBHOOK_SECRET=".str_repeat('a', 32).str_repeat('b', 32)."\n",
            $contents,
        );
        $this->assertSame([['name' => 'WEBHOOK_SECRET', 'line' => 2]], $repairs);
    }

    #[Test]
    public function it_does_not_modify_other_values_containing_spaces(): void
    {
        $contents = "APP_NAME=Almax Predictions\nTOKEN=abcd efgh\n";

        [$repaired, $repairs] = \DeploymentEnvironmentValidator::repairSpacedHexSecrets($contents);

        $this->assertSame($contents, $repaired);
        $this->assertSame([], $repairs);
    }

    #[Test]
    public function it_reports_unquoted_whitespace_without_including_values(): void
    {
        $invalid = \DeploymentEnvironmentValidator::findUnquotedWhitespace(implode("\n", [
            'APP_NAME="Almax Predictions"',
            'GOOD=value # an allowed comment',
            'BROKEN=never print this secret',
        ]));

        $this->assertSame([['name' => 'BROKEN', 'line' => 3]], $invalid);
    }
}
