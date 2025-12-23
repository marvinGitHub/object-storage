<?php

namespace melia\ObjectStorage\Event;

interface Events
{
    public const BEFORE_INITIAL_STORE = 'object-storage.before_initial_store';
    public const BEFORE_STORE = 'object-storage.before_store';
    public const AFTER_STORE = 'object-storage.after_store';
    public const BEFORE_UPDATE = 'object-storage.before_update';
    public const AFTER_UPDATE = 'object-storage.after_update';
    public const BEFORE_LOAD = 'object-storage.before_load';
    public const AFTER_LOAD = 'object-storage.after_load';
    public const BEFORE_DELETE = 'object-storage.before_delete';
    public const AFTER_DELETE = 'object-storage.after_delete';
    public const METADATA_SAVED = 'object-storage.metadata_saved';
    public const OBJECT_SAVED = 'object-storage.object_saved';
    public const STUB_CREATED = 'object-storage.stub_created';
    public const STUB_REMOVED = 'object-storage.stub_removed';
    public const CACHE_CLEARED = 'object-storage.cache_cleared';
    public const SHARED_LOCK_ACQUIRED = 'object-storage.shared_lock_acquired';
    public const EXCLUSIVE_LOCK_ACQUIRED = 'object-storage.exclusive_lock_acquired';
    public const LOCK_RELEASED = 'object-storage.lock_released';
    public const SAFE_MODE_ENABLED = 'object-storage.safe_mode_enabled';
    public const SAFE_MODE_DISABLED = 'object-storage.safe_mode_disabled';
    public const LIFETIME_CHANGED = 'object-storage.lifetime_changed';
    public const OBJECT_EXPIRED = 'object-storage.object_expired';
    public const CLASS_ALIAS_CREATED = 'object-storage.class_alias_created';
    public const CLASSNAME_CHANGED = 'object-storage.classname_changed';
    public const CACHE_HIT = 'object-storage.cache_hit';
    public const BEFORE_TYPE_CONVERSION = 'object-storage.before_type_conversion';
    public const LAZY_TYPE_NOT_SUPPORTED = 'object-storage.lazy_type_not_supported';

    public const CACHE_ENTRY_ADDED = 'object-storage.cache_entry_added';
    public const CACHE_ENTRY_REMOVED = 'object-storage.cache_entry_removed';
    public const METADATA_WRITE_FAILED = 'object-storage.metadata_write_failed';
    public const OBJECT_WRITE_FAILED = 'object-storage.object_write_failed';
    public const STUB_WRITE_FAILED = 'object-storage.stub_write_failed';
    public const CACHE_WRITE_FAILED = 'object-storage.cache_write_failed';
    public const IO_READ_FAILURE = 'object-storage.io_read_failure';
    public const JSON_DECODING_FAILURE = 'object-storage.json_decoding_failure';
    public const GRAPH_SERIALIZATION_FAILURE = 'object-storage.graph_serialization_failure';
    public const METADATA_LOADING_FAILURE = 'object-storage.metadata_loading_failure';
    public const OBJECT_SAVING_FAILURE = 'object-storage.object_saving_failure';
    public const OBJECT_LOADING_FAILURE = 'object-storage.object_loading_failure';
    public const OBJECT_DELETION_FAILURE = 'object-storage.object_deletion_failure';
    public const OBJECT_CORRUPTION_DETECTED = 'object-storage.object_corruption_detected';
}