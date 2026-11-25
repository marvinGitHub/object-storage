<?php

namespace melia\ObjectStorage\Viewer\Controller;

use melia\ObjectStorage\Viewer\App;

/**
 * The actions of one area of the viewer, a method each (see Routes): a page rendered with its template, or a JSON
 * response for the requests of the pages.
 */
abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }
}
