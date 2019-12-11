# moodle-local_removesuspended
Remove suspended users from groups. This creates a scheduled task to check the log table for recent user enrollment updates (ie a user being suspended from a course), removes suspended users from groups within that course and emails instructors with the details.
