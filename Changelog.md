# Changelog

All notable changes to this project will be documented in this file.

## [0.0.3] - Support for Sharding Depth

### Added
- Migration script for transitioning from version 0.0.2 to 0.0.3.
- Added policy which determines how child objects are stored. See StrategyInterface::POLICY_CHILD_WRITE_* constants.

### Important Note
- **Migration Required**: You must execute the migration script `002_003.php`. This script **must be executed from inside the `migrations/` directory** to ensure paths are resolved correctly.

## [0.0.2] - Support for Sharding

### Added
- Migration script for transitioning from version 0.0.1 to 0.0.2.

### Important Note
- **Migration Required**: You must execute the migration script `001_002.php`. This script **must be executed from inside the `migrations/` directory** to ensure paths are resolved correctly.

## [0.0.1] - Initial Release

### Added
- Initial release of the Object Storage