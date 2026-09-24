<?php

namespace Pxlrbt\LaravelStandards;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Process\Process;

class ComposerPlugin implements EventSubscriberInterface, PluginInterface
{
    protected Composer $composer;

    protected IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'runInstaller',
            ScriptEvents::POST_UPDATE_CMD => 'runInstaller',
        ];
    }

    public function runInstaller(Event $event): void
    {
        if (! $this->shouldRunInstaller()) {
            return;
        }

        $this->io->write('<info>Running pxlrbt standards installer…</info>');

        $process = new Process([PHP_BINARY, 'artisan', 'standards:install'], getcwd(), timeout: null);
        $process->setTty(Process::isTtySupported());
        $process->run(fn (string $type, string $buffer) => $this->io->write($buffer, false));
    }

    protected function shouldRunInstaller(): bool
    {
        return $this->io->isInteractive()
            && getenv('PXLRBT_STANDARDS_INSTALLING') === false
            && file_exists(getcwd().'/artisan')
            && ($this->composer->getPackage()->getExtra()['pxlrbt-standards']['installed'] ?? false) !== true;
    }
}
