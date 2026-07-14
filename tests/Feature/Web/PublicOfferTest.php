<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

class PublicOfferTest extends TestCase
{
    public function test_public_offer_page_renders_without_redirect_loop(): void
    {
        $this->get('/public-offer')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PublicOffer/Index'));
    }

    public function test_public_offer_page_with_trailing_slash_renders(): void
    {
        $this->get('/public-offer/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PublicOffer/Index'));
    }
}