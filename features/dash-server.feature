Feature: ee dash init server registration
  Server-level behaviour of `ee dash init` against the local EasyDash stub: the server lookup, registration, the EasyEngine SSH key, the hostname prompts and API error handling. The stub is stateful: add_server/add_site calls are returned by later get_server_list/get_site_list calls. These scenarios expect no site on the host (the site sync is covered in dash-sites.feature).

  Background:
    Given an EasyDash stub is running

  Scenario: A server already known to EasyDash is not registered again
    Given the EasyDash stub knows the server '203.0.113.10'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Server with IP 203.0.113.10 already exists on EasyDash. Skipping server addition.
      """
    And the EasyDash stub should have received 1 get_server_list request
    And the EasyDash stub should have received no add_server requests
    And the sandboxed authorized_keys file should contain the EasyEngine key 0 times
    And hostnamectl should not have been called

  Scenario: The server lookup posts the organization with the API token
    Given the EasyDash stub knows the server '203.0.113.10'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the last EasyDash get_server_list request should be a POST to '/api/method/easydash.easydash.doctype.server.server.get_server_list'
    And the last EasyDash get_server_list request should have the header 'Token' set to 'bh-dash-{RUN_ID}'
    And the last EasyDash get_server_list request should have the header 'Content-Type' set to 'application/json'
    And the last EasyDash get_server_list request body should contain JSON:
      """
      {"organization": "bh-dash-org"}
      """

  Scenario: A new server is registered, the EasyEngine key is authorised and the run stops there
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Success: Server integrated with EasyDash successfully. Please wait for sometime for site data to populate on EasyDash.
      """
    And the EasyDash stub should have received 1 get_server_list request
    And the EasyDash stub should have received 1 add_server request
    And the last EasyDash add_server request should be a POST to '/api/method/easydash.easydash.doctype.server.server.add_server'
    And the last EasyDash add_server request should have the header 'Token' set to 'bh-dash-{RUN_ID}'
    And the last EasyDash add_server request should have the header 'Content-Type' set to 'application/json'
    And the last EasyDash add_server request body should contain JSON:
      """
      {"hostname": "{HOSTNAME}", "public_ipv4": "203.0.113.10", "organization": "bh-dash-org"}
      """
    And the sandboxed authorized_keys file should contain the EasyEngine key 1 time
    # A successful registration exits before the site sync; EasyDash re-runs init later to sync sites.
    And the EasyDash stub should have received no get_site_list requests
    And the EasyDash stub should have received no add_site requests

  Scenario: Existing authorized_keys entries are kept when the key is added
    Given the sandboxed authorized_keys file contains:
      """
      ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBhdashExistingKeyForBehatOnly bh-dash@example.test
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the sandboxed authorized_keys file should contain:
      """
      ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBhdashExistingKeyForBehatOnly bh-dash@example.test
      {EASYENGINE_SSH_KEY}
      """
    And the sandboxed authorized_keys file should contain the EasyEngine key 1 time

  Scenario: The EasyEngine key is not duplicated when it is already authorised
    Given the sandboxed authorized_keys file contains:
      """
      {EASYENGINE_SSH_KEY}
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the EasyDash stub should have received 1 add_server request
    And the sandboxed authorized_keys file should contain the EasyEngine key 1 time

  Scenario: Running init twice registers the server only once
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    And I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Server with IP 203.0.113.10 already exists on EasyDash. Skipping server addition.
      """
    And the EasyDash stub should have received 2 get_server_list requests
    And the EasyDash stub should have received 1 add_server request
    And the sandboxed authorized_keys file should contain the EasyEngine key 1 time

  Scenario: Option values are trimmed before use
    When I run "bin/ee dash init --api='  bh-dash-{RUN_ID}  ' --org='  bh-dash-org  ' --ip='  203.0.113.10  ' --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash add_server request should have the header 'Token' set to 'bh-dash-{RUN_ID}'
    And the last EasyDash add_server request body should contain JSON:
      """
      {"public_ipv4": "203.0.113.10", "organization": "bh-dash-org"}
      """

  Scenario: A server list that only holds other IPs leads to a registration
    Given the EasyDash stub knows the server '198.51.100.7'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the EasyDash stub should have received 1 add_server request
    And the last EasyDash add_server request body should contain JSON:
      """
      {"public_ipv4": "203.0.113.10"}
      """

  Scenario: A failing server lookup is treated as an empty organization
    Given the EasyDash stub answers 'get_server_list' with status 500 and body:
      """
      {"exc_type": "InternalServerError"}
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      There are no servers in the org right now. Adding new one...
      """
    And the EasyDash stub should have received 1 add_server request

  Scenario: A server lookup answering non-JSON leads to a registration
    Given the EasyDash stub answers 'get_server_list' with status 200 and body:
      """
      <html>maintenance</html>
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the EasyDash stub should have received 1 add_server request

  # The exit code is asserted by the DASH-8 scenario below.
  Scenario: A rejected registration is reported with the response body and status
    Given the EasyDash stub answers 'add_server' with status 500 and body:
      """
      {"exc":"bh-dash registration refused"}
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then STDERR should contain:
      """
      Warning: Failed to integrate server with EasyDash. Response: {"exc":"bh-dash registration refused"}
      """
    And STDERR should contain:
      """
      Warning: Status code: 500
      """
    And STDOUT should not contain:
      """
      Server integrated with EasyDash successfully
      """

  # DASH-5: the key is appended before add_server is called and is left in place when the registration fails.
  @known-bug
  Scenario: A rejected registration does not leave the EasyEngine key authorised
    Given the EasyDash stub answers 'add_server' with status 500 and body:
      """
      {"exc":"bh-dash registration refused"}
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the sandboxed authorized_keys file should contain the EasyEngine key 0 times

  # DASH-8: after a failed registration init still syncs every site for a server EasyDash doesn't know, and exits 0.
  @known-bug
  Scenario: A rejected registration fails the command without syncing sites
    Given the EasyDash stub answers 'add_server' with status 500 and body:
      """
      {"exc":"bh-dash registration refused"}
      """
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1

  Scenario: An unreachable EasyDash API aborts the run before touching authorized_keys
    Given the EasyDash API URL is 'http://127.0.0.1:{CLOSED_PORT}/api/method/'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Failed to get url 'http://127.0.0.1:{CLOSED_PORT}/api/method/easydash.easydash.doctype.server.server.
      """
    And the sandboxed authorized_keys file should contain the EasyEngine key 0 times

  # DASH-4: http_request() halts on transport errors itself, so the "assume the server doesn't exist" fallback and its warning in server_exists_on_easydash() are dead code (same for the other catch blocks).
  @known-bug
  Scenario: An unreachable server lookup falls back to a warning as the code intends
    Given the EasyDash API URL is 'http://127.0.0.1:{CLOSED_PORT}/api/method/'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then STDERR should contain:
      """
      Warning: Error connecting to EasyDash API to get server list.
      """

  Scenario: A registration that times out aborts the run with an error
    Given the EasyDash stub answers 'add_server' after 12 seconds
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Failed to get url 'http://127.0.0.1:{STUB_PORT}/api/method/easydash.easydash.doctype.server.server.add_server'
      """
    And STDOUT should not contain:
      """
      Server integrated with EasyDash successfully
      """

  Scenario: An ed-api-url without a trailing slash is normalised
    Given the EasyDash API URL is '{STUB_URL}/api/method'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash get_server_list request should be a POST to '/api/method/easydash.easydash.doctype.server.server.get_server_list'
    And the last EasyDash add_server request should be a POST to '/api/method/easydash.easydash.doctype.server.server.add_server'

  Scenario: An EasyDash instance under a sub-path gets every request under that path
    Given the EasyDash API URL is '{STUB_URL}/easydash/api/method/'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the last EasyDash get_server_list request should be a POST to '/easydash/api/method/easydash.easydash.doctype.server.server.get_server_list'
    And the last EasyDash add_server request should be a POST to '/easydash/api/method/easydash.easydash.doctype.server.server.add_server'

  # DASH-11: the lookups are built from ed_base_url (ed-api-url minus a trailing "/api/method/"), the registration from ed-api-url, so an ed-api-url that doesn't end in /api/method/ splits them (here /proxy//api/method/… vs /proxy/…).
  @known-bug
  Scenario: Lookups and registration use the same configured API base
    Given the EasyDash API URL is '{STUB_URL}/proxy/'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the last EasyDash add_server request should be a POST to '/proxy/easydash.easydash.doctype.server.server.add_server'
    And the last EasyDash get_server_list request should be a POST to '/proxy/easydash.easydash.doctype.server.server.get_server_list'

  Scenario: A valid --hostname skips the hostname confirmation
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should not contain:
      """
      Is the hostname
      """
    And hostnamectl should not have been called

  # DASH-1: the --hostname value only skips the prompt; it is then overwritten by gethostname(), so EasyDash always gets the machine's hostname.
  @known-bug
  Scenario: The --hostname value is what EasyDash receives
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the last EasyDash add_server request body should contain JSON:
      """
      {"hostname": "bh-dash.example.test"}
      """

  Scenario: Without --hostname, confirming the machine hostname keeps it
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10" in the EasyDash sandbox with STDIN:
      """
      y
      """
    Then the return code should be 0
    And STDOUT should contain:
      """
      Is the hostname {HOSTNAME} correct? [y/n]
      """
    And hostnamectl should not have been called
    And the last EasyDash add_server request body should contain JSON:
      """
      {"hostname": "{HOSTNAME}"}
      """

  Scenario: An invalid --hostname falls back to the confirmation prompt
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bad_host" in the EasyDash sandbox with STDIN:
      """
      y
      """
    Then the return code should be 0
    And STDOUT should contain:
      """
      Is the hostname {HOSTNAME} correct? [y/n]
      """
    And hostnamectl should not have been called

  Scenario: Declining the hostname asks for a valid FQDN, sets it and registers it
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10" in the EasyDash sandbox with STDIN:
      """
      n
      not_a_host!
      BH-Dash-New.example.test
      """
    Then the return code should be 0
    And STDOUT should contain:
      """
      Please enter the FQDN hostname of the server:
      """
    And STDERR should contain:
      """
      Warning: Invalid hostname. Please enter a valid FQDN hostname.
      """
    # EE::input() lower-cases the answer.
    And hostnamectl should have been called with 'set-hostname bh-dash-new.example.test'
    And the last EasyDash add_server request body should contain JSON:
      """
      {"hostname": "bh-dash-new.example.test"}
      """

  # DASH-3: on EOF the confirmation reads "no" and EE::input() returns "" forever, so the hostname prompt loops endlessly (bounded here by timeout and head).
  @known-bug
  Scenario: Closing STDIN at the hostname prompt ends the command
    When I run "timeout 20 bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 2>&1 | head -n 40" in the EasyDash sandbox with STDIN:
      """
      n
      """
    Then STDOUT should contain 'Invalid hostname. Please enter a valid FQDN hostname.' at most 1 time
    And hostnamectl should not have been called

  # REL-4: v1.0.1 on main sends ee_version with the server data; develop doesn't.
  @known-bug
  Scenario: The registration reports the EasyEngine version
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the last EasyDash add_server request body should have the key 'ee_version'

  # DASH-6 (fixed in v4.13.2 by core masking, easyengine#1944), regression check: server_exists_on_easydash() logs the request headers, token included, at debug level, which always reaches ee.log.
  Scenario: The server lookup does not log the API token
    Given the EasyDash stub knows the server '203.0.113.10'
    When I run "bin/ee dash init --api=bh-dash-secret-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the file '/opt/easyengine/logs/ee.log' should not contain '"Token":"bh-dash-secret-{RUN_ID}"'

  # LOG-1 (fixed in v4.13.2), regression check: core used to log the full argv at debug level, so --api=<token> landed in ee.log.
  Scenario: The API token is not written to ee.log at all
    Given the EasyDash stub knows the server '203.0.113.10'
    When I run "bin/ee dash init --api=bh-dash-secret-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And the file '/opt/easyengine/logs/ee.log' should not contain 'bh-dash-secret-{RUN_ID}'

  # The sandbox's proxy guard makes every request not aimed at 127.0.0.1 fail at once, so the lookup never leaves the host.
  Scenario: Without --ip the public IP is looked up at api.ipify.org before EasyDash is contacted
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then STDERR should contain:
      """
      https://api.ipify.org
      """
    And the EasyDash stub should have received no add_server requests

  # DASH-4: http_request() halts on the transport error, so get_external_ip() never returns false and the IP prompt is never shown.
  @known-bug
  Scenario: An unreachable IP lookup falls back to asking for the public IP
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --hostname=bh-dash.example.test" in the EasyDash sandbox with STDIN:
      """
      203.0.113.10
      """
    Then STDOUT should contain:
      """
      Please enter the public IP address of the server:
      """
    And the last EasyDash add_server request body should contain JSON:
      """
      {"public_ipv4": "203.0.113.10"}
      """
