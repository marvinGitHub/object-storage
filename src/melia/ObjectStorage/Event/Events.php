<?php

namespace melia\ObjectStorage\Event;

interface Events
{
    public const BEFORE_STORE = 'object-storage.before_store';
    public const AFTER_STORE = 'object-storage.after_store';
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
}