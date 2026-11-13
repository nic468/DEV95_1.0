<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardPageAccessTest extends TestCase
{
    public function test_root_redirects_to_the_material_archive_for_local_loopback_guest(): void
    {
        $this->app['env'] = 'local';

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/')
            ->assertRedirect(route('materials.index'));
    }

    public function test_dashboard_requires_authentication_outside_local_environment(): void
    {
        $this->getJson('/dashboard')->assertUnauthorized();
    }

    public function test_local_loopback_guest_can_render_the_dashboard(): void
    {
        $this->app['env'] = 'local';

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard');
    }

    public function test_local_loopback_guest_can_render_the_material_archive(): void
    {
        $this->app['env'] = 'local';

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/materials')
            ->assertOk()
            ->assertViewIs('materials.index')
            ->assertSee('Planilhas arquivadas')
            ->assertSee('Dashboard')
            ->assertSee('Consultar materiais')
            ->assertDontSee('Selecionar programa')
            ->assertDontSee('materials/movements"');
    }

    public function test_a_selected_snapshot_renders_its_materials_inside_the_archive(): void
    {
        $this->app['env'] = 'local';
        $snapshotId = DB::table('upload_snapshots')
            ->orderByDesc('reference_date')
            ->orderByDesc('version')
            ->value('id');

        if ($snapshotId === null) {
            $this->markTestSkipped('An archived snapshot is required to render the inline materials table.');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get(route('materials.index', ['open_snapshot_id' => $snapshotId]))
            ->assertOk()
            ->assertSee('Materiais desta planilha')
            ->assertSee('Última revisão')
            ->assertSee('open_snapshot_id='.$snapshotId, false);
    }

    public function test_material_archive_rejects_non_loopback_guests(): void
    {
        $this->app['env'] = 'local';

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->get('/materials')
            ->assertForbidden();
    }

    public function test_local_dashboard_does_not_trust_forwarded_client_ip_headers(): void
    {
        $this->app['env'] = 'local';

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', '203.0.113.15')
            ->getJson('/api/dashboard/candlestick')
            ->assertUnauthorized();
    }

    public function test_local_dashboard_rejects_non_loopback_guests(): void
    {
        $this->app['env'] = 'local';

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_authenticated_user_can_render_the_dashboard(): void
    {
        $this->actingAs(new User)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard')
            ->assertSee('Dashboard de materiais')
            ->assertSee('Painel de materiais')
            ->assertSee('data-candlestick-url')
            ->assertSee('data-unit-charts-url')
            ->assertSee('data-snapshot-export-base')
            ->assertSee('Gráficos por unidade de medida')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }
}
