<?php

/**
 * @file
 * Documentation related to Package Manager.
 */

/**
 * Package Manager is an API-only module which provides the scaffolding and
 * functionality needed for Drupal to make changes to its own running code base
 * via Composer. It doesn't provide any user interface.
 *
 * At the center of Package Manager is the concept of a staging area. A staging
 * area is a complete copy of the active Drupal code base, created in a
 * temporary directory that isn't accessible over the web. The staging area
 * doesn't include site-specific assets that aren't managed by Composer, such as
 * settings.php, uploaded files, or SQLite databases.
 *
 * Package Manager can run Composer commands in the staging area to require or
 * update packages in it, and then copy those changes back into the live,
 * running code base (which is referred to as the "active directory"). The
 * staging area can then be safely deleted. These four distinct operations --
 * create, require, apply, and destroy -- comprise the "stage life cycle."
 *
 * Package Manager's PHP API is based on \Drupal\package_manager\Stage, which
 * controls the stage life cycle. This class may be extended to implement custom
 * behavior, but in most cases, custom code should use the event system to
 * interact with the stage.
 *
 * Only one staging area can exist at any given time, and it is "owned" by the
 * user or session that originally created it. Only the owner can perform
 * operations on the staging area, and only using the same class (i.e.,
 * \Drupal\package_manager\Stage or a subclass) they used to create it.
 *
 * Events are dispatched before and after each operation in the stage life
 * cycle. There are two types of events: pre-operation and post-operation.
 * Pre-operation event subscribers can analyze the state of the staging area, or
 * the system at large, and flag errors if they detect any problems. If errors
 * are flagged, the operation is prevented. Therefore, pre-operation events are
 * helpful to ensure that the staging area is in a valid state. Post-operation
 * events are simple triggers allowing custom code to react when an operation
 * is complete. They cannot flag errors to block stage operations (although
 * they can use the core messenger and logging systems as needed).
 *
 * All stage events extend \Drupal\package_manager\Event\StageEvent, and all
 * pre-operation events extend
 * \Drupal\package_manager\Event\PreOperationStageEvent. All events have a
 * getStage() method which allows access to the stage object itself.
 *
 * The stage dispatches the following events during its life cycle:
 *
 * - \Drupal\package_manager\Event\PreCreateEvent
 *   Dispatched before the staging area is created. At this point, the stage
 *   will have recorded which user or session owns it, so another staging area
 *   cannot be created until the current one is destroyed. If subscribers flag
 *   errors during this event, the stage will release its ownership. This is
 *   the earliest possible time to detect problems that might prevent the
 *   stage from completing its life cycle successfully. This event is dispatched
 *   only once during a stage's life cycle.
 *
 * - \Drupal\package_manager\Event\PostCreateEvent
 *   Dispatched after the staging area is created, which means that the running
 *   Drupal code base has been copied into a separate, temporary location. This
 *   event is dispatched only once during a stage's life cycle.
 *
 * - \Drupal\package_manager\Event\PreRequireEvent
 *   Dispatched before one or more Composer packages are required into the
 *   staging area. This event may be dispatched multiple times during a stage's
 *   life cycle.
 *
 * - \Drupal\package_manager\Event\PostRequireEvent
 *   Dispatched after one or more Composer packages have been added to the
 *   staging area. This event may be dispatched multiple times during a stage's
 *   life cycle.
 *
 * - \Drupal\package_manager\Event\PreApplyEvent
 *   Dispatched before changes in the staging area (i.e., new or updated
 *   packages) are copied to the active directory (the running Drupal code
 *   base). This is the final opportunity for event subscribers to flag errors
 *   before the active directory is modified. Once the active directory has
 *   been modified, the changes cannot be undone. This event may be dispatched
 *   multiple times during a stage's life cycle.
 *
 * - \Drupal\package_manager\Event\PostApplyEvent
 *   Dispatched after changes in the staging area have been copied to the active
 *   directory. This event may be dispatched multiple times during a stage's
 *   life cycle.
 *
 * - \Drupal\package_manager\Event\PreDestroyEvent
 *   Dispatched before the temporary staging directory is deleted and the stage
 *   releases its ownership. This event is dispatched only once during a stage's
 *   life cycle.
 *
 * - \Drupal\package_manager\Event\PostDestroy
 *   Dispatched after the temporary staging directory is deleted and the stage
 *   has released its ownership. This event is dispatched only once during a
 *   stage's life cycle.
 *
 * The public API of any stage consists of the following methods:
 *
 * - \Drupal\package_manager\Stage::create()
 *   Creates the staging area, records ownership, and dispatches pre- and
 *   post-create events. Returns a unique token which calling code must use to
 *   verify stage ownership before performing operations on the staging area
 *   in subsequent requests (when the staging area is created, its ownership
 *   is automatically verified for the duration of the current request). See
 *   \Drupal\package_manager\Stage::claim() for more information.
 *
 * - \Drupal\package_manager\Stage::require()
 *   Adds and/or updates packages in the staging area and dispatches pre- and
 *   post-require events.
 *
 * - \Drupal\package_manager\Stage::apply()
 *   Copies changes from the staging area into the active directory, and
 *   dispatches pre- and post-apply events.
 *
 * - \Drupal\package_manager\Stage::destroy()
 *   Destroys the staging area, releases ownership, and dispatches pre- and
 *   post-destroy events.
 *
 * - \Drupal\package_manager\Stage::getActiveComposer()
 *   \Drupal\package_manager\Stage::getStageComposer()
 *   These methods initialize an instance of Composer's API in the active
 *   directory and staging area, respectively, and return an object which can
 *   be used by event subscribers to inspect the directory and get relevant
 *   information from Composer's API, such as what packages are installed and
 *   where.
 *
 * Package Manager automatically enforces certain constraints at various points
 * of the stage life cycle, to ensure that both the active directory and staging
 * area are kept in a safe, consistent state:
 *
 * - If the composer.lock file is changed (e.g., by installing or updating a
 *   package) in the active directory after a staging area has been created,
 *   Package Manager will refuse to make any further changes to the staging
 *   area or apply the staged changes to the active directory.
 * - The Drupal site must not have any pending database updates.
 * - Composer must use HTTPS to download packages and metadata (i.e., Composer's
 *   secure-http configuration option must be enabled). This is the default
 *   behavior.
 * - The Drupal root, and vendor directory, must be writable.
 * - A supported version of the Composer executable must be accessible by PHP.
 *   By default, its path will be auto-detected, but can be explicitly set in
 *   the package_manager.settings config.
 * - Certain files are never copied into the staging area because they are
 *   irrelevant to Composer or Package Manager. Examples include settings.php
 *   and related files, public and private files, SQLite databases, and git
 *   repositories. Custom code can use
 *   \Drupal\package_manager\Event\PreCreateEvent::excludePath() to exclude a
 *   specific path from being copied from the active directory into the staging
 *   area, or \Drupal\package_manager\Event\PreApplyEvent::excludePath() to
 *   exclude a specific path from being copied from the staging area back into
 *   the active directory.
 */
