import { readFileSync } from 'node:fs';

class FixtureError extends Error {
  constructor(code) {
    super(code);
    this.code = code;
  }
}

function fail(code) {
  throw new FixtureError(code);
}

function assertString(value, code, min = 1, max = 512) {
  if (typeof value !== 'string' || value.length < min || value.length > max || value.includes('\u0000')) {
    fail(code);
  }
  return value;
}

function assertUuid(value, code) {
  const text = assertString(value, code, 36, 36);
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(text)) {
    fail(code);
  }
  return text;
}

function delay(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

const stagedInputPath = '/tmp/class-archive-immich-technical-library-input.json';

let input;
try {
  const inputArguments = process.argv.slice(2);
  let inputBytes;
  if (inputArguments.length === 0) {
    inputBytes = readFileSync(0);
  } else if (inputArguments.length === 2 && inputArguments[0] === '--input-file' && inputArguments[1] === stagedInputPath) {
    inputBytes = readFileSync(stagedInputPath);
  } else {
    fail('input_source_invalid');
  }
  input = JSON.parse(inputBytes.toString('utf8'));
} catch {
  console.error('IMMICH_TECHNICAL_LIBRARY=FAIL reason=input_invalid');
  process.exit(1);
}

try {
  const email = assertString(input.email, 'email_invalid', 6, 190);
  const password = assertString(input.password, 'password_invalid', 24, 190);
  const name = assertString(input.name, 'name_invalid', 3, 190);
  const libraryName = assertString(input.libraryName, 'library_name_invalid', 3, 190);
  const expectedMinimum = input.minimumAssetCount;
  if (!Number.isSafeInteger(expectedMinimum) || expectedMinimum < 1 || expectedMinimum > 100000) {
    fail('expected_asset_count_invalid');
  }

  const request = async (operation, path, method = 'GET', body = undefined, accessToken = null) => {
    const headers = { accept: 'application/json' };
    if (body !== undefined) {
      headers['content-type'] = 'application/json';
    }
    if (accessToken !== null) {
      headers.authorization = `Bearer ${accessToken}`;
    }

    let response;
    try {
      response = await fetch(`http://127.0.0.1:2283/api${path}`, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
      });
    } catch {
      fail(`${operation}_transport`);
    }
    if (!response.ok) {
      fail(`${operation}_http_${response.status}`);
    }
    if (response.status === 204) {
      return null;
    }
    try {
      return await response.json();
    } catch {
      fail(`${operation}_response_invalid`);
    }
  };

  const admin = await request('admin_signup', '/auth/admin-sign-up', 'POST', { email, password, name });
  const adminId = assertUuid(admin?.id, 'admin_id_invalid');

  const login = await request('technical_login', '/auth/login', 'POST', { email, password });
  const accessToken = assertString(login?.accessToken, 'access_token_invalid', 32, 8192);
  if (login?.isAdmin !== true || login?.userId !== adminId) {
    fail('technical_login_identity_invalid');
  }

  const library = await request('library_create', '/libraries', 'POST', {
    ownerId: adminId,
    name: libraryName,
    importPaths: ['/external/piwigo-upload', '/external/piwigo-galleries'],
  }, accessToken);
  const libraryId = assertUuid(library?.id, 'library_id_invalid');
  if (library?.ownerId !== adminId || !Array.isArray(library?.importPaths)) {
    fail('library_owner_or_paths_invalid');
  }
  const importPaths = new Set(library.importPaths);
  if (!importPaths.has('/external/piwigo-upload') || !importPaths.has('/external/piwigo-galleries')) {
    fail('library_paths_invalid');
  }

  await request('library_scan', `/libraries/${libraryId}/scan`, 'POST', undefined, accessToken);

  let statistics = null;
  for (let attempt = 0; attempt < 120; attempt += 1) {
    statistics = await request('library_statistics', `/libraries/${libraryId}/statistics`, 'GET', undefined, accessToken);
    if (Number.isSafeInteger(statistics?.total) && statistics.total >= expectedMinimum) {
      break;
    }
    await delay(1000);
  }
  if (!Number.isSafeInteger(statistics?.total) || statistics.total < expectedMinimum) {
    fail('library_scan_incomplete');
  }

  // Never print the access token, password, email, user id, library id, file
  // path or any upstream response body. The PowerShell runner resets these
  // disposable volumes immediately after this exact lifecycle test.
  console.log(`IMMICH_TECHNICAL_LIBRARY=PASS assets=${statistics.total} minimum=${expectedMinimum}`);
  process.exit(0);
} catch (error) {
  const reason = error instanceof FixtureError ? error.code : 'unexpected';
  console.error(`IMMICH_TECHNICAL_LIBRARY=FAIL reason=${reason}`);
  process.exit(1);
}
