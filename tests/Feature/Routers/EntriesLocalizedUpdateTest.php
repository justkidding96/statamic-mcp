<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

/**
 * A localization stores only the fields that were translated and inherits the
 * rest from its origin. `update` validated the localization's own data merged
 * with the incoming fields, so every required field that lives on the origin
 * counted as missing and no localization with a required non-localizable field
 * could be updated at all.
 */
class EntriesLocalizedUpdateTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.system.multisite' => true]);

        Site::setSites([
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
            'nl' => ['name' => 'Nederlands', 'locale' => 'nl_NL', 'url' => '/nl/'],
        ]);

        $this->router = new EntriesRouter;
        $this->collectionHandle = 'spaces-' . bin2hex(random_bytes(4));

        Collection::make($this->collectionHandle)
            ->title('Spaces')
            ->sites(['en', 'nl'])
            ->save();

        Blueprint::make('space')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
                ['handle' => 'number', 'field' => ['type' => 'integer', 'validate' => ['required']]],
                ['handle' => 'intro', 'field' => ['type' => 'textarea', 'localizable' => true]],
            ],
        ])->save();

        Entry::make()
            ->id('space-1')
            ->collection($this->collectionHandle)
            ->locale('en')
            ->slug('space-1')
            ->data(['title' => 'Space 1', 'number' => 1, 'intro' => 'Welcome'])
            ->save();

        Stache::refresh();

        $localized = $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'space-1',
            'site' => 'nl',
            'data' => ['intro' => 'Welkom'],
        ]);
        $this->assertTrue($localized['success'], json_encode($localized['errors'] ?? []));
    }

    public function test_updates_a_localization_whose_required_fields_live_on_the_origin(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'space-1',
            'site' => 'nl',
            'data' => ['intro' => 'Hallo'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $localized = Entry::find('space-1')->in('nl');
        $this->assertSame('Hallo', $localized->get('intro'));
        // Validation saw the inherited values; storage must not copy them.
        $this->assertFalse($localized->data()->has('title'));
        $this->assertFalse($localized->data()->has('number'));
    }

    public function test_a_required_field_the_caller_clears_on_the_localization_still_fails(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'space-1',
            'site' => 'nl',
            'data' => ['title' => null],
        ]);

        $this->assertFalse($result['success']);
    }
}
