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
 * At the center of Package Manager is the concept of a stage directory. A
 * stage directory is a complete copy of the active Drupal code base, created
 * in a temporary directory that isn't accessible over the web. The stage
 * directory doesn't include site-specific assets that aren't managed by
 * Composer, such as settings.php, uploaded files, or SQLite databases.
 *
 * Package Manager can run Composer commands in the stage directory to require
 * or update packages in it, and then copy those changes back into the live,
 * running code base (which is referred to as the "active directory"). The
 * stage directory can then be safely deleted. These four distinct operations
 * -- create, require, apply, and destroy -- comprise the "stage life cycle."
 *
 * Package Manager's PHP API is based on \Drupal\package_manager\Stage, which
 * controls the stage life cycle. This class may be extended to implement custom
 * behavior, but in most cases, custom code should use the event system to
 * interact with the stage.
 *
 * Only one stage directory can exist at any given time, and it is "owned" by
 * the user or session that originally created it. Only the owner can perform
 * operations on the stage directory, and only using the same class (i.e.,
 * \Drupal\package_manager\Stage or a subclass) they used to create it.
 *
 * Events are dispatched before and after each operation in the stage life
 * cycle. There are two types of events: pre-operation and post-operation.
 * Pre-operation event subscribers can analyze the state of the stage
 * directory, or the system at large, and flag errors if they detect any
 * problems. If errors are flagged, the operation is prevented. Therefore,
 * pre-operation events are helpful to ensure that the stage directory is in a
 * valid state. Post-operation events are simple triggers allowing custom code
 * to react when an operation is complete. They cannot flag errors to block
 * stage operations (although they can use the core messenger and logging
 * systems as needed).
 *
 * All stage events extend \Drupal\package_manager\Event\StageEvent, and all
 * pre-operation events extend
 * \Drupal\package_manager\Event\PreOperationStageEvent. All events have a
 * $stage property which allows access to the stage object itself.
 *
 * The stage dispatches the following events during its life cycle:
 *
 * - \Drupal\package_manager\Event\CollectIgnoredPathsEvent
 *   Dispatched before the stage directory is created and also before changes in
 *   the stage directory are copied to the active directory. This event may be
 *   dispatched multiple times during a stage's life cycle.
 *
 * - \Drupal\package_manager\Event\PreCreateEvent
 *   Dispatched before the stage directory is created. At this point, the
 *   stage will have recorded which user or session owns it, so another stage
 *   directory cannot be created until the current one is destroyed. If
 *   subscribers flag errors during this event, the stage will release its
 *   ownership. This is the earliest possible time to detect problems that might
 *   prevent the stage from completing its life cycle successfully. This event
 *   is dispatched only once during a stage's life cycle.
 *
 * - \Drupal\package_manager\Event\PostCreateEvent
 *   Dispatched after the stage directory is created, which means that the
 *   running Drupal code base has been copied into a separate, temporary
 *   location. This event is dispatched only once during a stage's life cycle.
 *
 * - \Drupal\package_manager\Event\PreRequireEvent
 *   Dispatched before one or more Composer packages are required into the
 *   stage directory. This event may be dispatched multiple times during a
 *   stage's life cycle.
 *
 * - \Drupal\package_manager\Event\PostRequireEvent
 *   Dispatched after one or more Composer packages have been added to the
 *   stage directory. This event may be dispatched multiple times during a
 *   stage's life cycle.
 *
 * - \Drupal\package_manager\Event\PreApplyEvent
 *   Dispatched before changes in the stage directory (i.e., new or updated
 *   packages) are copied to the active directory (the running Drupal code
 *   base). This is the final opportunity for event subscribers to flag errors
 *   before the active directory is modified. Once the active directory has
 *   been modified, the changes cannot be undone. This event may be dispatched
 *   multiple times during a stage's life cycle.
 *
 * - \Drupal\package_manager\Event\PostApplyEvent
 *   Dispatched after changes in the stage directory have been copied to the
 *   active directory. This event may be dispatched multiple times during a
 *   stage's life cycle.
 *
 * - \Drupal\package_manager\Event\PreDestroyEvent
 *   Dispatched before the temporary stage directory is deleted and the stage
 *   releases its ownership. This event is dispatched only once during a stage's
 *   life cycle.
 *
 * - \Drupal\package_manager\Event\PostDestroy
 *   Dispatched after the temporary stage directory is deleted and the stage
 *   has released its ownership. This event is dispatched only once during a
 *   stage's life cycle.
 *
 *  There are some cases where there is no point for an event to trigger further
 *  event subscribers, in which case the event propagation should be stopped.
 *  For example, in a situation where Composer or Composer Stager cannot work
 *  at all, or a security vulnerability is detected, the event propagation must
 *  be stopped to prevent further event subscribers from breaking. For example,
 *  Package Manager stops event propagation if:
 * - The stage directory is a subdirectory of the active directory.
 * - No composer.json file exists in active directory.
 * - Package Manager has been deliberately disabled in the current environment.
 *   See \Drupal\package_manager\Validator\EnvironmentSupportValidator
 *
 * The public API of any stage consists of the following methods:
 *
 * - \Drupal\package_manager\Stage::create()
 *   Creates the stage directory, records ownership, and dispatches pre- and
 *   post-create events. Returns a unique token which calling code must use to
 *   verify stage ownership before performing operations on the stage
 *   directory in subsequent requests (when the stage directory is created,
 *   its ownership is automatically verified for the duration of the current
 *   request). See \Drupal\package_manager\Stage::claim() for more information.
 *
 * - \Drupal\package_manager\Stage::require()
 *   Adds and/or updates packages in the stage directory and dispatches pre-
 *   and post-require events.
 *
 * - \Drupal\package_manager\Stage::apply()
 *   Copies changes from the stage directory into the active directory, and
 *   dispatches pre- and post-apply events.
 *
 * - \Drupal\package_manager\Stage::destroy()
 *   Destroys the stage directory, releases ownership, and dispatches pre- and
 *   post-destroy events.
 *
 * - \Drupal\package_manager\Stage::getActiveComposer()
 *   \Drupal\package_manager\Stage::getStageComposer()
 *   These methods initialize an instance of Composer's API in the active
 *   directory and stage directory, respectively, and return an object which
 *   can be used by event subscribers to inspect the directory and get relevant
 *   information from Composer's API, such as what packages are installed and
 *   where.
 *
 * If problems occur during any point of the stage life cycle, a
 * \Drupal\package_manager\Exception\StageException is thrown. If problems were
 * detected during one of the "pre" operations, a subclass of that is thrown:
 * \Drupal\package_manager\Exception\StageValidationException. This will contain
 * \Drupal\package_manager\ValidationResult objects.
 * The Package Manager module does not catch or handle these exceptions: they
 * provide the foundation for other modules to build user experiences for
 * installing, updating and removing packages.
 *
 * Package Manager automatically enforces certain constraints at various points
 * of the stage life cycle, to ensure that both the active directory and stage
 * directory are kept in a safe, consistent state:
 *
 * - If the composer.lock file is changed (e.g., by installing or updating a
 *   package) in the active directory after a stage directory has been created
 *   ,Package Manager will refuse to make any further changes to the stage
 *   directory or apply the staged changes to the active directory.
 * - Composer plugins are able to perform arbitrary file system operations, and
 *   hence could perform actions that make it impossible for Package Manager to
 *   guarantee the Drupal site will continue to work correctly. For that reason,
 *   Package Manager will refuse to make any further changes if untrusted
 *   composer plugins are installed or staged. Additional composer plugins are
 *   vetted over time. If you know what you are doing, it is possible to trust
 *   additional composer plugins by modifying package_manager.settings's
 *   "additional_trusted_composer_plugins" setting.
 * - The Drupal site must not have any pending database updates.
 * - Composer must use HTTPS to download packages and metadata (i.e., Composer's
 *   secure-http configuration option must be enabled). This is the default
 *   behavior.
 * - The Drupal root, and vendor directory, must be writable.
 * - A supported version of the Composer executable must be accessible by PHP.
 *   By default, its path will be auto-detected, but can be explicitly set in
 *   the package_manager.settings config.
 * - Certain files are never copied into the stage directory because they are
 *   irrelevant to Composer or Package Manager. Examples include settings.php
 *   and related files, public and private files, SQLite databases, and git
 *   repositories. Custom code can use
 *   \Drupal\package_manager\Stage::getIgnoredPaths() which dispatches the
 *   CollectIgnoredPathsEvent to collect ignored paths that are excluded from
 *   being copied from the active directory into the stage directory, and also
 *   from being copied from the stage directory back into the active directory.
 *
 * \Drupal\package_manager\Event\StatusCheckEvent
 * Dispatched to check the status of the Drupal site and whether Package Manager
 * can function properly. These checks can be performed anytime, so this event
 * may be dispatched multiple times.
 *
 * The above constraints are enforced by services that subscribe to events
 * dispatched during the life cycle of stage. These event subscribers are either
 * labeled "validator" (validate the state at a certain point in the life cycle)
 * or "exclude" (to exclude files from being copied from the active to the stage
 * directory or vice versa).
 * To be able to do enforce those constraints, these event subscribers need to
 * know where to look: \Drupal\package_manager\PathLocator informs them where
 * the project root, the vendor directory, et cetera are.
 * Whenever a problem is encountered, an event subscriber should generate one or
 * more messages (with a summary if there's multiple) to explain it to the user
 * and call \Drupal\package_manager\Event\PreOperationStageEvent::addError() or
 * \Drupal\package_manager\Event\StatusCheckEvent::addWarning(). If the
 * subscriber catches an unexpected exception it should use
 * \Drupal\package_manager\Event\PreOperationStageEvent::addErrorFromThrowable().
 */
