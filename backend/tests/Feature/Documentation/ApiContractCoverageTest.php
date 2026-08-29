<?php

namespace Tests\Feature\Documentation;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiContractCoverageTest extends TestCase
{
    public function test_every_api_route_has_a_documented_row(): void
    {
        $documented = $this->documentedRoutes();
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            $this->assertContains(
                $route->methods()[0].' /'.$route->uri(),
                $documented,
                "No docs/api-contract.md row for {$route->methods()[0]} /{$route->uri()}",
            );
        }
    }

    public function test_every_documented_row_is_a_real_route(): void
    {
        $live = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'))
            ->map(fn ($route) => $route->methods()[0].' /'.$route->uri())
            ->all();
        foreach ($this->documentedRoutes() as $row) {
            $this->assertContains($row, $live, "docs/api-contract.md documents {$row}, which no longer exists");
        }
    }

    /** @return list<string> "METHOD /path" pairs, e.g. "GET /api/v1/health" */
    private function documentedRoutes(): array
    {
        $rows = [];
        foreach (file(base_path('../docs/api-contract.md')) as $line) {
            if (preg_match('/^\|\s*`(\w+)`\s*\|\s*`([^`]+)`\s*\|/', $line, $matches)) {
                $rows[] = "{$matches[1]} {$matches[2]}";
            }
        }

        return $rows;
    }
}
