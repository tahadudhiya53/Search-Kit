<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * Where an indexing operation stands. Successful operations are deleted rather than marked done.
 */
enum IndexOperationStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
}
