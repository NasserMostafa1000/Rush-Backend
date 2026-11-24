<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_docs_redirect_and_swagger_page(): void
    {
        $this->get('/')->assertRedirect('/docs');
        $this->get('/docs')->assertOk();
        $this->get('/docs/openapi.yaml')->assertOk();
    }
}
