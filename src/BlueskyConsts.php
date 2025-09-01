<?php

namespace williamsdb\php2bluesky;

class BlueskyConsts
{
    // don't change these unless Bluesky changes the limits
    const MAX_IMAGE_UPLOAD_SIZE = 1000000;
    const MAX_IMAGE_UPLOAD = 4;
    const MAX_VIDEO_UPLOAD_SIZE = 50000000;
    const MAX_VIDEO_UPLOAD = 1;
    const MAX_VIDEO_DURATION = 60; // in seconds
    const MIN_POST_SIZE = 3;
    const MAX_POST_SIZE = 300;
    const FILE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/jpg',
        'image/webp',
        'video/mp4',
        'video/mpeg',
        'video/webm',
        'video/mov',
    ];
    const ALLOWED_LABELS = [
        'porn',
        'sexual',
        'nudity',
        'graphic-media',
        '!no-unauthenticated'
    ];
}
