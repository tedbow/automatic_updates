# `StagedProjectsValidatorTest` Fixtures

### new_project_added
Simulates a scenario where, while updating Drupal core in a site with no non-core dependencies, a new contrib module and
a new custom module are unexpectedly installed (as runtime and dev dependencies, respectively). Additionally, two new
non-Drupal packages are installed (again, one as a runtime dependency, the other dev).

**Expectation**: The validator should complain about the new modules; the new non-Drupal packages are ignored.

### no_errors
Simulates a scenario where, while updating Drupal core in a site with two unpinned contrib dependencies (one runtime and
one dev), no Drupal packages are updated, but two non-Drupal libraries are removed (again, one a runtime dependency, the
other dev), two are updated (same arrangement), and two are added (ditto).

**Expectation**: The validator to raise no errors; changes to non-Drupal packages are ignored.

### project_removed
Simulates a scenario where, while updating Drupal core in a site with no non-core dependencies, an installed contrib
theme and an installed custom theme are unexpectedly removed (from runtime and dev dependencies, respectively).
Additionally, two installed non-Drupal packages are removed (again, one from a runtime dependency, the other dev). The 
existing contrib dependencies' installed versions are unchanged.

**Expectation**: The validator should complain about the removed themes; the removed non-Drupal packages are ignored.

### version_changed
Simulates a scenario where, while updating Drupal core in a site with two unpinned contrib dependencies (one runtime and
one dev), the contrib modules are unexpectedly updated, as are two installed non-Drupal packages (again, one a runtime
dependency, the other dev).

**Expectation**: The validator should complain about the updated modules; the updated non-Drupal packages are ignored.
