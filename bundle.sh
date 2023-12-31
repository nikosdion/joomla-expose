#!/bin/bash

#
# @package   ExposeJoomla
# @copyright Copyright (c)2020-2024 Nicholas K. Dionysopoulos
# @license   GNU General Public License version 3, or later
#

pushd plugins/system/expose
zip -r ../../../plg_system_expose.zip * .htaccess
popd