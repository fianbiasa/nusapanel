<?php

namespace Tests\Feature\Files;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Agent\FakeAgentClient;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class FileManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $transferDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transferDir = sys_get_temp_dir().'/rayxo-transfer-'.uniqid();
        config(['hosting.files.transfer_dir' => $this->transferDir, 'hosting.files.chunk_bytes' => 2048]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->transferDir);

        parent::tearDown();
    }

    public function test_admins_cannot_reach_the_file_manager()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('files.index'))
            ->assertForbidden();
    }

    public function test_the_page_lists_a_folder_of_the_home()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $account = User::factory()->create(['username' => 'budi']);
        Domain::factory()->for($account, 'account')->create(['name' => 'budi.test']);

        $this->actingAs($account)
            ->get(route('files.index', ['path' => 'domains']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('files/index')
                ->where('path', 'domains')
                ->where('home', '/home/budi')
                ->where('listing.entries.0.name', 'budi.test')
                ->where('listing.entries.0.type', 'directory')
                ->where('error', null)
                ->where('chunk_bytes', 2048));

        $this->assertSame([['method' => 'fs.list', 'params' => ['username' => 'budi', 'path' => 'domains']]], $agent->calls());
    }

    public function test_a_path_that_could_leave_the_home_goes_back_to_the_home()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs(User::factory()->create())
            ->get(route('files.index', ['path' => '../sari']))
            ->assertRedirect(route('files.index'));
    }

    public function test_a_folder_the_agent_cannot_list_shows_why()
    {
        $this->mockAgentFailure('not_found');

        $this->actingAs(User::factory()->create())
            ->get(route('files.index', ['path' => 'gone']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('listing', null)
                ->where('error', 'The file or folder does not exist (anymore).'));
    }

    public function test_creating_a_folder()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->from(route('files.index', ['path' => 'domains']))
            ->post(route('files.create'), ['directory' => 'domains', 'name' => 'new site', 'type' => 'folder'])
            ->assertRedirect(route('files.index', ['path' => 'domains']))
            ->assertInertiaFlash('toast.message', 'Folder new site created.');

        $this->assertSame([['method' => 'fs.mkdir', 'params' => ['username' => 'budi', 'path' => 'domains/new site']]], $agent->calls());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'file.folder_created', 'actor_id' => $account->id]);
    }

    public function test_a_name_that_is_taken_shows_the_agents_reason()
    {
        $this->mockAgentFailure('exists');

        $this->actingAs(User::factory()->create())
            ->post(route('files.create'), ['directory' => '', 'name' => 'index.php', 'type' => 'file'])
            ->assertSessionHasErrors(['name' => 'A file or folder with that name already exists.']);

        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'file.created']);
    }

    public function test_a_name_with_a_slash_never_reaches_the_agent()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs(User::factory()->create())
            ->post(route('files.rename'), ['path' => 'a.txt', 'name' => '../b.txt'])
            ->assertSessionHasErrors(['name' => 'The name may not be empty, "." or "..", and may not contain a slash or control characters.']);
    }

    public function test_moving_reports_what_could_not_be_moved()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->with('fs.move', ['username' => 'budi', 'paths' => ['a.txt', 'b.txt'], 'destination' => 'site'], 600)
            ->andReturn(['done' => ['a.txt'], 'failed' => [['path' => 'b.txt', 'reason' => 'exists', 'message' => 'site/b.txt already exists']]]));

        $this->actingAs(User::factory()->create(['username' => 'budi']))
            ->post(route('files.transfer'), ['paths' => ['a.txt', 'b.txt'], 'target' => 'site', 'move' => true])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', '1 item moved. b.txt: A file or folder with that name already exists.');

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'file.moved']);
    }

    public function test_permissions_are_sent_as_a_number()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);

        $this->actingAs(User::factory()->create(['username' => 'budi']))
            ->post(route('files.permissions'), ['paths' => ['wp-config.php'], 'mode' => '640'])
            ->assertInertiaFlash('toast.message', 'Permissions of 1 item changed.');

        $this->assertSame(0640, $agent->calls()[0]['params']['mode']);

        $this->actingAs(User::factory()->create())
            ->post(route('files.permissions'), ['paths' => ['wp-config.php'], 'mode' => '4755'])
            ->assertSessionHasErrors('mode');
    }

    public function test_an_upload_arrives_in_chunks_and_the_agent_stores_it()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $transfer = hash('sha256', $account->id.'|upload-0123456789abcdef');
        $uploaded = null;

        $this->mock(AgentClient::class, function (MockInterface $mock) use ($transfer, &$uploaded) {
            $mock->shouldReceive('call')->once()
                ->withArgs(function (string $method, array $params, ?int $timeout) use ($transfer, &$uploaded): bool {
                    $uploaded = file_get_contents($this->transferDir.'/'.$transfer.'.upload');

                    return $method === 'fs.upload' && $timeout === 600 && $params === [
                        'username' => 'budi',
                        'transfer' => $transfer,
                        'path' => 'site/big.zip',
                        'overwrite' => false,
                    ];
                })
                ->andReturn(['name' => 'big.zip', 'type' => 'file', 'size' => 3000]);
        });

        $this->actingAs($account)
            ->post(route('files.upload'), $this->chunk(0, 2, str_repeat('a', 2048)))
            ->assertOk()
            ->assertJson(['stored' => false]);

        $this->actingAs($account)
            ->post(route('files.upload'), $this->chunk(1, 2, str_repeat('b', 952)))
            ->assertOk()
            ->assertJson(['stored' => true, 'entry' => ['name' => 'big.zip']]);

        $this->assertSame(str_repeat('a', 2048).str_repeat('b', 952), $uploaded);
        $this->assertFileDoesNotExist($this->transferDir.'/'.$transfer.'.upload');
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'file.uploaded', 'actor_id' => $account->id]);
    }

    public function test_a_chunk_without_the_ones_before_it_is_refused()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs(User::factory()->create())
            ->postJson(route('files.upload'), $this->chunk(1, 2, 'end'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['chunk' => 'Part of the upload went missing. Upload the file again.']);
    }

    public function test_an_account_cannot_continue_another_accounts_upload()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs(User::factory()->create())
            ->post(route('files.upload'), $this->chunk(0, 2, str_repeat('a', 2048)))
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->postJson(route('files.upload'), $this->chunk(1, 2, 'stolen'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chunk');
    }

    public function test_a_download_sends_the_file_and_leaves_nothing_behind()
    {
        $this->app->instance(AgentClient::class, new FakeAgentClient);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('files.download', ['path' => 'domains/budi.test/public_html/index.php']))
            ->assertOk()
            ->assertDownload('index.php');

        ob_start();
        $response->baseResponse->sendContent();
        $sent = ob_get_clean();

        $this->assertSame("Simulated content of domains/budi.test/public_html/index.php\n", $sent);
        $this->assertSame([], glob($this->transferDir.'/*') ?: []);
    }

    public function test_the_preview_only_shows_images_in_a_sandbox()
    {
        $this->app->instance(AgentClient::class, new FakeAgentClient);
        $account = User::factory()->create();

        $this->actingAs($account)
            ->get(route('files.preview', ['path' => 'index.php']))
            ->assertNotFound();

        $this->actingAs($account)
            ->get(route('files.preview', ['path' => 'images/logo.svg']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_editor_opens_and_saves_a_file()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->get(route('files.edit', ['path' => 'site/index.php']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('files/edit')
                ->where('path', 'site/index.php')
                ->where('version', 'simulated-1')
                ->has('content'));

        $this->actingAs($account)
            ->putJson(route('files.save'), ['path' => 'site/index.php', 'content' => '<?php echo 2;', 'version' => 'simulated-1'])
            ->assertOk()
            ->assertJsonPath('size', 13);

        $this->assertSame(['username' => 'budi', 'path' => 'site/index.php', 'content' => '<?php echo 2;', 'version' => 'simulated-1'], $agent->calls()[1]['params']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'file.saved', 'actor_id' => $account->id]);
    }

    public function test_a_save_after_someone_else_changed_the_file_is_refused()
    {
        $this->mockAgentFailure('changed');

        $this->actingAs(User::factory()->create())
            ->putJson(route('files.save'), ['path' => 'index.php', 'content' => 'x', 'version' => 'old'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content' => 'The file was changed after you opened it. Reload it and make your change again.']);
    }

    public function test_a_file_that_is_not_text_goes_back_to_its_folder()
    {
        $this->mockAgentFailure('not_text');

        $this->actingAs(User::factory()->create())
            ->get(route('files.edit', ['path' => 'images/logo.png']))
            ->assertRedirect(route('files.index', ['path' => 'images']))
            ->assertInertiaFlash('toast.message', 'This is not a text file, so it cannot be opened in the editor.');
    }

    public function test_a_suspended_account_cannot_change_files()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs(User::factory()->suspended()->create())
            ->post(route('files.delete'), ['paths' => ['index.php']])
            ->assertSessionHasErrors(['username' => 'This account is suspended or not active yet.']);

        $this->assertGuest();
    }

    public function test_the_trash_lists_restores_and_empties()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $account = User::factory()->create(['username' => 'budi']);
        $id = '20261110-093000-0a1b2c3d';

        $this->actingAs($account)
            ->get(route('files.trash'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('files/trash')->where('items', []));

        $this->actingAs($account)
            ->post(route('files.restore'), ['ids' => [$id]])
            ->assertInertiaFlash('toast.message', '1 item restored.');

        $this->actingAs($account)
            ->post(route('files.purge'), ['all' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($account)
            ->post(route('files.purge'), ['ids' => ['../../x']])
            ->assertSessionHasErrors('ids.0');

        $this->assertSame(
            ['fs.trash_list', 'fs.trash_restore', 'fs.trash_empty'],
            array_column($agent->calls(), 'method'),
        );
        $this->assertSame(['username' => 'budi', 'ids' => [$id]], $agent->calls()[1]['params']);
    }

    public function test_compressing_writes_the_archive_into_the_folder()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->post(route('files.compress'), ['paths' => ['site/a.php', 'site/css'], 'directory' => 'site', 'name' => 'site.tar.gz', 'format' => 'tar.gz'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Archive site.tar.gz created.');

        $this->assertSame(
            ['username' => 'budi', 'paths' => ['site/a.php', 'site/css'], 'destination' => 'site/site.tar.gz', 'format' => 'tar.gz'],
            $agent->calls()[0]['params'],
        );
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'file.compressed', 'actor_id' => $account->id]);

        $this->actingAs($account)
            ->post(route('files.compress'), ['paths' => ['site/a.php'], 'directory' => 'site', 'name' => 'a.rar', 'format' => 'rar'])
            ->assertSessionHasErrors('format');
    }

    public function test_extracting_reports_what_was_left_out()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->with('fs.extract', ['username' => 'budi', 'path' => 'site/wp.zip', 'destination' => 'site/wp', 'overwrite' => false], 600)
            ->andReturn(['destination' => 'site/wp', 'files' => 10, 'folders' => 2, 'links' => 0, 'skipped' => 3, 'refused' => 1]));

        $this->actingAs(User::factory()->create(['username' => 'budi']))
            ->post(route('files.extract'), ['path' => 'site/wp.zip', 'target' => 'site/wp', 'overwrite' => false])
            ->assertInertiaFlash('toast.type', 'warning')
            ->assertInertiaFlash('toast.message', '10 files and 2 folders extracted. 3 already existed and were left as they are. 1 were refused because they would land outside the folder.');
    }

    public function test_searching_and_counting_answer_with_json()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->getJson(route('files.search', ['path' => 'site', 'query' => 'index']))
            ->assertOk()
            ->assertJsonPath('hits.0.path', 'site/index.html')
            ->assertJsonPath('truncated', false);

        $this->actingAs($account)
            ->postJson(route('files.size'), ['paths' => ['site']])
            ->assertOk()
            ->assertJsonPath('usage.0.path', 'site')
            ->assertJsonPath('usage.0.files', 42);

        $this->assertSame(['username' => 'budi', 'path' => 'site', 'query' => 'index'], $agent->calls()[0]['params']);

        $this->actingAs($account)
            ->getJson(route('files.search', ['path' => '', 'query' => '../etc']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');
    }

    public function test_abandoned_transfers_are_cleaned_up()
    {
        File::ensureDirectoryExists($this->transferDir);
        $old = $this->transferDir.'/'.str_repeat('a', 64).'.upload';
        $recent = $this->transferDir.'/'.str_repeat('b', 64).'.download';
        $other = $this->transferDir.'/notes.txt';

        foreach ([$old, $recent, $other] as $file) {
            file_put_contents($file, 'x');
        }

        touch($old, now()->subHours(7)->getTimestamp());
        touch($other, now()->subDays(2)->getTimestamp());

        $this->artisan('rayxo:clean-transfers')
            ->expectsOutputToContain('1 abandoned transfer removed.')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $this->assertFileExists($other);
    }

    /**
     * @return array<string, mixed>
     */
    private function chunk(int $index, int $total, string $content): array
    {
        return [
            'upload_id' => 'upload-0123456789abcdef',
            'path' => 'site/big.zip',
            'index' => $index,
            'total' => $total,
            'overwrite' => false,
            'chunk' => UploadedFile::fake()->createWithContent('blob', $content),
        ];
    }

    private function mockAgentFailure(string $reason): void
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->andThrow(new AgentException('refused by the agent', -32000, reason: $reason)));
    }
}
