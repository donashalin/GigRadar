<?php

it('loads the home page', fn () => $this->get('/')->assertOk());
