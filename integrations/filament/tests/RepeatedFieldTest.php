<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Tests;

use ImagePuzzle\Filament\Tests\Fixtures\RepeatedPuzzles;
use Livewire\Livewire;

final class RepeatedFieldTest extends TestCase
{
    public function testEachRepeaterItemIsValidatedAndSavedOnItsOwn(): void
    {
        $this->signIn();

        $form = Livewire::test(RepeatedPuzzles::class)
            ->set('data.rounds', [
                'a' => ['config' => self::config('swap', 1)],
                'b' => ['config' => self::config('jigsaw', 2)],
            ])
            ->call('save');
        $form->assertHasErrors(['data.rounds.b.config']);
        $form->assertHasNoErrors(['data.rounds.a.config']);

        $form->set('data.rounds.b.config', self::config('sliding', 3))->call('save');
        $form->assertHasNoErrors();
        $form->assertSet('saved.rounds', [
                ['config' => self::config('swap', 1)],
                ['config' => self::config('sliding', 3)],
            ]);
    }

    public function testRestrictedModesAreExplainedToTheAuthor(): void
    {
        $this->signIn();

        Livewire::test(RepeatedPuzzles::class)
            ->set('data.rounds', ['a' => ['config' => self::config('rotation', 1)]])
            ->call('save')
            ->assertSee(__('image-puzzle-filament::messages.kind_not_allowed', ['kind' => 'rotation']));
    }

    public function testEveryInstanceRendersItsOwnConfigurator(): void
    {
        $this->signIn();

        $html = Livewire::test(RepeatedPuzzles::class)
            ->set('data.rounds', ['a' => ['config' => null], 'b' => ['config' => null]])
            ->html();

        self::assertSame(3, substr_count($html, '<image-puzzle-configurator'));
        self::assertSame(2, substr_count($html, 'kinds="swap sliding"'));
        self::assertMatchesRegularExpression('/<image-puzzle-configurator[^>]*\sdisabled[\s>]/', $html);
        self::assertStringContainsString('data-disabled="true"', $html);
        preg_match_all('/<image-puzzle-configurator[^>]*\sid="([^"]+)"/', $html, $ids);
        self::assertCount(3, array_unique($ids[1]), 'Each configurator needs a unique id for its label.');
    }

    public function testADisabledFieldIsNotSaved(): void
    {
        $this->signIn();

        $form = Livewire::test(RepeatedPuzzles::class)
            ->set('data.locked', self::config('swap', 4))
            ->set('data.rounds', [])
            ->call('save');
        $form->assertHasNoErrors();
        $form->assertSet('saved', ['rounds' => []]);
    }
}
