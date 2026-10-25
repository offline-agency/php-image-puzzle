<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;
use ImagePuzzle\Filament\PuzzleModels;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloads the record's generated puzzle as a ZIP: definition.json, every
 * asset it references and the build configuration that reproduces it.
 * Requires the "view" policy ability on the record and ext-zip.
 */
class DownloadPuzzleAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'downloadPuzzle';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('image-puzzle-filament::messages.download'));
        $this->icon(Heroicon::OutlinedArrowDownTray);
        $this->authorize('view');
        $this->visible(static fn (mixed $record): bool => $record instanceof PuzzleRecord && $record->imagePuzzleDefinitionPath() !== null);
        $this->action(static function (mixed $record): ?BinaryFileResponse {
            if (!$record instanceof PuzzleRecord || !$record instanceof Model) {
                return null;
            }
            if (!class_exists(\ZipArchive::class)) {
                Notification::make()->danger()->title(__('image-puzzle-filament::messages.zip_unavailable'))->send();

                return null;
            }
            $zip = self::archive($record);
            if ($zip === null) {
                Notification::make()->danger()->title(__('image-puzzle-filament::messages.not_generated'))->send();

                return null;
            }

            return (new BinaryFileResponse($zip, 200, ['Content-Type' => 'application/zip'], false))
                ->setContentDisposition('attachment', str_replace('.', '-', app(PuzzleModels::class)->keyFor($record)) . '.zip')
                ->deleteFileAfterSend();
        });
    }

    /** Builds the archive in a temporary file; null when the record has no generated puzzle. */
    public static function archive(Model & PuzzleRecord $record): ?string
    {
        $definition = $record->imagePuzzleDefinitionPath();
        $directory = $definition === null ? null : dirname($definition);
        $base = app(PuzzleModels::class)->directoryFor($record) . '/';
        if ($definition === null || $directory === null || !is_file($definition) || !str_starts_with($definition, $base)) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'image-puzzle-');
        if ($path === false) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && !$file->isLink()) {
                $zip->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1)));
            }
        }
        $config = $record->imagePuzzleConfiguration();
        if ($config !== null) {
            $zip->addFromString('build-config.json', json_encode($config->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }
        $zip->close();

        return $path;
    }
}
