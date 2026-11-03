<?php

namespace Kpapy\Demo\Controllers;

use Kpapy\Auth\LoginController;
use Kpapy\Demo\Demo;
use Kpapy\Http\Responder;

/**
 * The app login of kpapy with the demo's own settings (two-factor methods, token lifetime).
 */
class DemoLogin extends LoginController
{
    protected function setup(): void
    {
        $this->kpapy     = Demo::config();
        $this->responder = new Responder($this->kpapy, $this->response);

        helper('auth');
    }
}
