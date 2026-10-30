<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\PuzzleService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\ImagePuzzle;
use Config\Services;
use ImagePuzzle\Exception\ImagePuzzleException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\GameKind;

/**
 * Upload a photo, build a puzzle, play it and keep validated progress.
 */
class Puzzles extends BaseController
{
    /** @var list<string> */
    protected $helpers = ['form', 'url'];

    private PuzzleService $puzzles;

    private ImagePuzzle $settings;

    public function __construct(?PuzzleService $puzzles = null)
    {
        $this->puzzles = $puzzles ?? Services::puzzles();
        $this->settings = config(ImagePuzzle::class);
    }

    public function new(): string
    {
        return view('puzzles/new', ['kinds' => $this->settings->kinds, 'error' => null]);
    }

    public function create(): ResponseInterface|string
    {
        $rules = [
            'photo' => "uploaded[photo]|max_size[photo,{$this->settings->maxUploadKb}]|is_image[photo]|mime_in[photo,image/png,image/jpeg,image/gif,image/webp]",
            'kind' => 'required|in_list[' . implode(',', $this->settings->kinds) . ']',
            'preset' => 'required|in_list[easy,medium,hard]',
            'seed' => 'permit_empty|is_natural|less_than_equal_to[2147483647]',
        ];
        if (!$this->validate($rules)) {
            return $this->failForm(implode(' ', $this->validator === null ? ['Check the form.'] : $this->validator->getErrors()));
        }
        $file = $this->request instanceof IncomingRequest ? $this->request->getFile('photo') : null;
        if (!$file instanceof UploadedFile || !$file->isValid() || $file->hasMoved()) {
            return $this->failForm('Upload a photo.');
        }
        // A random, server-chosen name in a directory outside the web root.
        $name = $file->getRandomName();
        $file->move($this->settings->uploadDirectory, $name);
        $path = rtrim($this->settings->uploadDirectory, '/') . '/' . $name;

        $config = new BuildConfiguration(GameKind::from($this->postString('kind')), $this->postString('preset'), [], (int) $this->postString('seed'));
        try {
            $id = $this->puzzles->create($path, $config);
        } catch (ResourceLimitExceededException $e) {
            log_message('notice', 'Puzzle rejected: {message}', ['message' => $e->getMessage()]);

            return $this->failForm('The photo is too large.');
        } catch (ImagePuzzleException $e) {
            log_message('notice', 'Puzzle rejected: {message}', ['message' => $e->getMessage()]);

            return $this->failForm('The photo could not be turned into a puzzle.');
        } finally {
            // The builder keeps an upright copy; the original is no longer needed.
            @unlink($path);
        }

        return redirect()->to('play/' . $id);
    }

    public function show(string $id): string
    {
        $definition = $this->puzzles->definition($id);

        return view('puzzles/show', [
            'id' => $id,
            'kind' => $definition->kind->value,
            'definitionUrl' => base_url($this->puzzles->definitionPath($id)),
        ]);
    }

    public function progress(string $id): ResponseInterface
    {
        $state = $this->puzzles->progress($id);

        return $state === null ? $this->response->setStatusCode(204) : $this->response->setJSON($state);
    }

    public function saveProgress(string $id): ResponseInterface
    {
        try {
            $state = json_decode($this->request->getBody() ?? '', true, 32, JSON_THROW_ON_ERROR);
            $saved = $this->puzzles->saveProgress($id, $state);
        } catch (InvalidDefinitionException|\JsonException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }

        return $this->response->setJSON(['moves' => $saved->moves, 'completed' => $saved->completed]);
    }

    /** A validated text field ('' when absent). */
    private function postString(string $key): string
    {
        $value = $this->request->getPost($key);

        return is_string($value) ? $value : '';
    }

    private function failForm(string $error): string
    {
        $this->response->setStatusCode(422);

        return view('puzzles/new', ['kinds' => $this->settings->kinds, 'error' => $error]);
    }
}
