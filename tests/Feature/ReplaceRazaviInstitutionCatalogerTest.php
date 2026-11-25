<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReplaceRazaviInstitutionCatalogerTest extends TestCase
{
    public function test_an_unknown_cataloger_id_is_still_a_404()
    {
        $this->get(route('catalogers.show', ['id' => '999999999']))->assertNotFound();
    }
}
