#!/bin/bash
if [ "${TRAVIS_PULL_REQUEST_BRANCH}" == "" ];
then
    BUILD_BRANCH=${TRAVIS_BRANCH};
else
    BUILD_BRANCH=${TRAVIS_PULL_REQUEST_BRANCH};
fi;
echo -e "\nGet boot.sh from from the branch (${BUILD_BRANCH})";
curl -H "Authorization: token ${TOKEN}" -o "boot.sh" "https://raw.githubusercontent.com/ComboStrap/combo_test/${BUILD_BRANCH}/resources/script/ci/boot.sh"
