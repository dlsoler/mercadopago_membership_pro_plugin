const path = require('path');
const fs = require('fs');
const https = require('https');
const assert = require('assert').strict;

const { series, src, dest } = require('gulp');
const zip = require('gulp-zip');
const xmlTransformer = require("gulp-xml-transformer");
const semver = require('semver');
const dateFns = require('date-fns');
const dirSync = require('gulp-directory-sync');
const log = require('fancy-log');
const del = require('del');
changed = require('gulp-changed');

/**
 * The plugin name used for the manifest filename and output filename.
 */
const PLUGIN_NAME = 'dls_mercadopago';

/**
 * Path of Joomla used for development
 */
const OS_MEMBERSHIP_PRO_PLUGINS_PATH =`/var/www/asorarevista/components/com_osmembership/plugins`;

const TESTING_USERS_FILENAME = 'testing_users.json';

/**
 * Path where the plugin is installed during the development
 */
const PLUGIN_PATHNAME =`${OS_MEMBERSHIP_PRO_PLUGINS_PATH}/${PLUGIN_NAME}.php`;
const PLUGIN_LIB_PATH = `${OS_MEMBERSHIP_PRO_PLUGINS_PATH}/mercadopago`
/**
 * Filename of the distribution file
 */
const OUTPUT_FILENAME = `${PLUGIN_NAME}.zip`;

const srcPath = path.posix.join(__dirname, '../src');
const destPath = path.posix.join(__dirname, '../dist');
const manifestPathname = path.posix.join(srcPath, `${PLUGIN_NAME}.xml`);

/**
 * Task to set the date in the manifest file
 */
function setBuildDate() {
  return src(manifestPathname)
  .pipe(xmlTransformer([
    { path: '//creationDate', text: dateFns.format(new Date(), 'MMMM Do YYYY') },
  ]))
  .pipe(dest(srcPath));
}

/**
 * Task to build the distribution file
 */
function buildDistributionFile() {
  return src([`${srcPath}/**`, `!${srcPath}/*.md`])
        .pipe(zip(OUTPUT_FILENAME))
        .pipe(dest(destPath));
}

/**
 * Increase the version number
 * @param {string} release It should be 'patch', 'minor' o 'major' according what you want to increase
 */
function increaseVersion(release) {
  return src(manifestPathname)
  .pipe(xmlTransformer((xml, libxmljs) => {
  // 'xml' is libxmljs Document object.
  const versionValueElement = xml.get('//version');
  const currentVersionText = versionValueElement.text();
  const newVersionText = semver.inc(currentVersionText, release);
  versionValueElement.text(newVersionText);
  // must return libxmljs Document object.
  return xml;
}))
.pipe(dest(srcPath));
}

/**
 * Increases the patch number of the version
 */
function increasePatch() {
  return increaseVersion('patch');
}

/**
 * Increase the minor number of the version
 */
function increaseMinorNumber() {
  return increaseVersion('minor');
}

/**
 * Increase the major number of the version
 */
function increaseMajorNumber() {
  return increaseVersion('major');
}

/**
 * Synchronizes the plugin lib folder with the src folder
 */
function syncLibFolders() {
  return src('.')
    .pipe(
      dirSync(
          PLUGIN_LIB_PATH,
          `${srcPath}/mercadopago`,
          { printSummary: true}
      )
  ).on ('error', log);
}

function cleanDist() {
  return del(['dist/**']);
}

/**
 * Synchronizes the main plugin file to the src folder
 */
function syncFiles() {
  // return src(PLUGIN_PATHNAME).
  //   pipe(dest(`${srcPath}/${PLUGIN_NAME}.php`, { overwrite: true }));
  console.log('srcPath: ', srcPath);
  return src([
    `${OS_MEMBERSHIP_PRO_PLUGINS_PATH}/${PLUGIN_NAME}.php`,
    `${OS_MEMBERSHIP_PRO_PLUGINS_PATH}/${PLUGIN_NAME}.xml`,
  ])
		.pipe(changed(srcPath))
		.pipe(dest(`${srcPath}/`, {overwrite: true}));
}



function getMPTestingUser(cb) {
  const MP_PRODUCTION_TOKEN = process.env.MP_PRODUCTION_TOKEN;
  assert.ok(MP_PRODUCTION_TOKEN, 'The environment variable MP_PRODUCTION_TOKEN is undefined!');

  function writeDataToFile(filename, data, callback) {
    fs.writeFile(filename, d, 'utf8', (err, res) => {
      if (err) {
        console.error(`Error writing the file ${filename}: `, err);
        return callback(err);
      }
      callback(null, res);
    });
  }

  function readDataFile(filename, callback) {
    fs.readFile(filename, (err, data) => {
      if (err) {
        console.error(err);
        return callback(err);
      }
      try {
        const newData = JSON.parse(data);
        callback(null, newData);
      } catch(e) {
        callback(e);
      }
    });
  }

  function readParseWriteFile(filename, dataToWrite, callback) {
    readDataFile(filename, (err, readedData) => {
      let newData = [];
      if(Array.isArray(readedData)) {
        newData.push(dataToWrite);
      } else {
        if (readedData instanceof Object) {
          newData = Object.assign({}, newData, dataToWrite);
        }
      }
      writeDataToFile(fliename, newData, callback);
    });
  }

  
  const options = {
    url: `https://api.mercadopago.com/users/test_user?access_token=${MP_PRODUCTION_TOKEN}`,
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    }
  };

  const postData = {"site_id":"MLA"};

  const req = https.request(options, (res) => {
    console.log('statusCode:', res.statusCode);
    console.log('headers:', res.headers);
  
    res.on('data', (d) => {
      process.stdout.write(d);
      fs.access(TESTING_USERS_FILENAME, fs.constants.F_OK | fs.constants.W_OK, (err) => {
        if (err) {
          if (err.code !== 'ENOENT') {
            // This should not happen, the file is read-only
            console.error(`${file} is read-only`);
            return cb(err);
          } else {
            return writeDataToFile(TESTING_USERS_FILENAME, d, cb);
          }
        }
        readParseWriteFile(TESTING_USERS_FILENAME, d, cb);
      });
    });
  });
  
  req.on('error', (e) => {
    console.error(e);
  });
  
  req.write(postData);
  req.end();
}

exports.setBuildDate = setBuildDate;
exports.increasePatch = increasePatch;
exports.increaseMinorNumber = increaseMinorNumber;
exports.increaseMajorNumber = increaseMajorNumber;
exports.syncLibFolders = syncLibFolders;
exports.syncFiles = syncFiles;
exports.cleanDist = cleanDist;

exports.default = series(setBuildDate, cleanDist, buildDistributionFile);
