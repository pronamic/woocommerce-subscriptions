# 3rd-Party Libraries

Code in this directory is pulled in from other sources. It is also used *instead of* a `vendor/` directory with Composer.

The libraries and files that should be tracked in Git are the following:

* `action-scheduler/` &ndash; Links to Prospress/action-scheduler
* `class-wc-datetime.php` &ndash; A copy of the `WC_DateTime`, for compatibility reasons.

## Action Scheduler

The Action scheduler library has been incorporated into WooCommerce Subscriptions as a Git Subtree.

### Setup

To set up Action Scheduler as a subtree in your local copy of Subscriptions, run the following command from the root of the Subscriptions directory:

```bash
git remote add subtree-action-scheduler https://github.com/Prospress/action-scheduler.git
```

### Updating

Whenever Action Scheduler is updated, the updated version will need to be pulled into Subscriptions. This can be done with the following two commands:

```bash
git fetch subtree-action-scheduler
git subtree pull --prefix includes/libraries/action-scheduler subtree-action-scheduler <BRANCH> --squash
```

In the command above, `<BRANCH>` should be replaced with either `master`, or a tag representing the latest release.
