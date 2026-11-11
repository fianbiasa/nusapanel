<?php

namespace App\Enums;

/**
 * Which of a website's two names, example.com and www.example.com, its
 * visitors end up on.
 */
enum WwwMode: string
{
    /** Serve the website on both names. */
    case Both = 'both';

    /** Send visitors of example.com to www.example.com (301). */
    case Www = 'www';

    /** Send visitors of www.example.com to example.com (301). */
    case Bare = 'bare';
}
