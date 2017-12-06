CONTENTS OF THIS FILE
---------------------
 * Introduction
 * Requirements
 * Installation
 * Configuration
 * CLI
 * Developers
 * Troubleshooting
 * Sponsors
 * Maintainers

INTRODUCTION
------------
This module import vacancies from an external source and store them as nodes
in the included content type Vacancy. The module also include a view block
listing all vacancies.

Out of the box does the module support importing vacancies from Emply
(http://emply.com/) and HR Manager (https://www.hr-manager.net).
Other sources can be added in VacancySource-plugins.

REQUIREMENTS
------------
 * Drupal 8.3 or later.

INSTALLATION
------------
 * Install as you would normally install a contributed drupal module. See:
  https://www.drupal.org/documentation/install/modules-themes/modules-8
  for further information.

CONFIGURATION
-------------
After installation go to /admin/config/services/vacancy-importer-settings and
configure the vacancy source plugin that you will use.

Emply
-----
Configure the following mandatory fields:

 * Media ID
 * API Key
 * API Domain (The API domain / URL in the format: https://company.emply.net.)

Optional settings

 * Insert JobId in facts (The Job ID is inserted automatically into the imported
 facts block)
 * Fact ID - Work Area (1)
 * Fact ID - Work Time (1)
 * Fact ID - Employment Type (1)
 * Fact ID - Work Place (1)

(1) Insert the ID attribtue value from the fact-tag in the XML source mapped
to this field / taxonomy in Drupal. The facts-section in the XML source differs
from Emply configuration to Emply configuration.

HR Manager
----------
Configure the following mandatory fields:

 * API Name (Your name that is used as part of the API Url. [YOUR NAME] from
 http://api.hr-manager.net/jobportal.svc/[YOUR NAME]/positionlist/xml/?incads=1)

Import cron settings
--------------------
The module can import automatically by a configured internal using cron. Enable
automatic import using cron and configure the interval.

Archive cron settings
---------------------
The module can automatically archive imported vacancies X minutes after the
apply due date. You can both configure the number of minutes delay and how
often the archive cron job should be executed.

Notice: there will be a delay in automatic archive due to the cron interval.

CLI
----------
The module support importing vacancies using both Drush and Drupal Console
using the following commands.

Drush <8
--------
 * drush vacancy-importer-import
 * drush vacancy-importer:import
 * drush vi:import
 * drush vii

Drush 9+
-------
 * drush vacancy-importer:import
 * drush vi:import
 * drush vii

Drupal Console
--------------
 * drupal vacancy:importer:import
 * drupal vi:import
 * drupal vii

DEVELOPERS
----------
The Vacancy Importer module provide the following ways for developers to
extend the functionality:

Source plugins
--------------
New source plugins can be added using the VacancySource annotation. See the
existing Emply and HrManager plugins.

TROUBLESHOOTING
---------------
-

SPONSORS
--------
 * FFW - https://ffwagency.com

MAINTAINERS
-----------
Current maintainers:
 * Jens Beltofte (beltofte) - https://drupal.org/u/beltofte