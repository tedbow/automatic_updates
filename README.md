Automatic Updates
---------------

### About this Module

Updating a Drupal site is difficult, time-consuming, and expensive. It is a
tricky problem that, on its face appears easy, however, ensuring secure and
reliable updates that give assurance to site owners and availability to site
visitors.

The Automatic Updates module is not yet in core. In its initial form, it is
being made available as a contributed module. Please note that Automatic Updates
is a [Strategic Initiative](https://www.drupal.org/project/ideas/issues/2940731)
for the Drupal Project. The Initiative is still in progress and additional
features and bug fixes are regularly added.

The primary use case for this module:

- **Public service announcements (PSAs)**

Announcements for highly critical security releases for core and contrib modules
are done infrequently. When a PSA is released, site owners should review their
sites to verify they are up to date with the latest releases and the site is in
a good state to quickly update once the fixes are provided to the community.

- **Update readiness checks**

Not all sites are able to always update. The readiness checks are an automated
method to determine if a site is ready for automatically updating once a new
release is provided to the community. For example, sites that have un-run
database updates, are mounted on read only filesystems or do not have sufficient
disk space to update in-place can't receive automatic updates. If your site is
failing readiness checks and a PSA is released, it is important to resolve the
underlying readiness issues so the site can quickly be updated.

- **In-Place Updates**

Once the PSA service has notified a Drupal site owner of an available update,
and the readiness checks have confirmed that the site is ready to be updated,
the Automatic Update service can then apply the update.


### Goals

The Automatic Update service for Drupal aims to simplify the update process and
provide confidence that an update will apply cleanly. Updates are currently
limited to Drupal Core for tarball (non-Composer managed) websites.


### Demo

> [Watch a demo](https://youtu.be/fT2--EBhzuE) of the module from DriesNote at
> DrupalCon Amsterdam 2019.


### Installing the Automatic Updates Module

Note: Use (not just installation) of the module on a Composer managed site is
not supported.

1. Copy/upload the automatic_updates module to the modules directory of your
   Drupal installation.

1. Enable the 'Automatic Updates' module in 'Extend' (/admin/modules).

1. Configure the module to enable PSA notifications, readiness checks and
   in-place updates (/admin/config/automatic_updates).


### Automatic Updates Initiative

- Follow and read up on
  [Ideas queue - Automatic Updates initiative](https://www.drupal.org/project/ideas/issues/2940731)
