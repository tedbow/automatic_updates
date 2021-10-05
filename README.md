Automatic Updates
---------------
🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥

Warning the 8.x-2.x version of this module is still in development and should only be used development and testing.

🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥🔥

### Automatic Updates Initiative

- Follow and read up on
  [Ideas queue - Automatic Updates initiative](https://www.drupal.org/project/ideas/issues/2940731)


### Manual Testing instructions:

1. Create a Drupal project via: `composer create-project drupal/recommended-project:9.2.5 test_updates_project`. Change `9.2.5` to the patch release before the most current version of Drupal. For instance, if the latest version of Drupal core is `9.2.8` then use `9.2.7`
2. `cd test_updates_project`
3. `composer require drupal/automatic_updates:2.x-dev`
4. `cd web`
5. Install Drupal: `php ./core/scripts/drupal quick-start standard`
6. You should see a browser open and log you into the new Drupal site.
7. Click "Extend" in the sidebar.
8. Enable the "Automatic Updates" module.
9. Click the "Updates" tab.
10. You should see an update form with the next of Drupal core as the "Recommend Version". For instance if in step #1 you ran `composer create-project drupal/recommended-project:9.2.5 test_updates_project` and the latest version is `9.2.6` you will see `9.2.6` as the "Recommend Version".
11. Click the "Download these updates" button.
12. You should see a "Downloading updates" screen.
13. Next you should see a "Ready to update" page.
14. Click the "Continue" button at the bottom.
15. You should see a "Apply updates" screen.
16. You should be redirected to the "Available updates" page and see the message "Update Complete".
17. You should see your version of core is up-to-date.
18. 🎉
