<?php

namespace App\Http\Controllers;

class ReaderController extends Controller
{
    public function index(int $bookId)
    {
        return view('reader.index', compact('bookId'));
    }
}
