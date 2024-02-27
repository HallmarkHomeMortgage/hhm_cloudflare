<?php
/*
##############################################################################
# Hallmark Home Mortgage hhm_cloudflare                         Version 1.00 #
# Copyright 2024                        https://www.hallmarkhomemortgage.com #
##############################################################################
*/

// SQLite database file
$dbFile = __DIR__ . '/../databases/sqlite3.db';

// Cloudflare API details
define('CLOUDFLARE_API_TOKEN', '-cloudflare-api-token-goes-here-');

// Cloudflare account ID
define('CLOUDFLARE_ACCOUNT_ID', '-cloudflare-account-id-goes-here-');

// Define pagination limit
define('CLOUDFLARE_PAGINATION_LIMIT', 5);

// Define Cloudflare API Delay in seconds
define('CLOUDFLARE_API_DELAY', 2);

// Debug flag
define('CLOUDFLARE_DEBUG', FALSE);

// LOG_EMERG = Emergency: system is unusable
// LOG_ALERT = Action must be taken immediately
// LOG_CRIT = Critical: critical conditions
// LOG_ERR = Error: error conditions
// LOG_WARNING = Warning: warning conditions
// LOG_NOTICE = normal, but significant, condition
// LOG_INFO = Informational: informational messages
// LOG_DEBUG = Debug: debug-level messages
define('HHM_SyslogMode', TRUE);
define('HHM_SyslogSeverity', LOG_DEBUG);
define('HHM_SyslogHost', 'Remote');

function HHM_AuditLogSyslog($Severity_Level, $Component= '', $Message= '') {
    if (CONSTANT('HHM_SyslogMode')===TRUE) {
        // Do we log this message ?
        if (CONSTANT('HHM_SyslogSeverity') >= $Severity_Level) {
            // Sanitize variables
            if (CONSTANT("HHM_SyslogHost")==="Remote") {
                openlog($Component, LOG_NDELAY | LOG_PID, LOG_SYSLOG);
                syslog($Severity_Level, $Message);
                closelog();
            } else {
                file_put_contents("output.log", $Severity_Level . " | " . $Component . " | " . $Message . "\n", FILE_APPEND);
            }
        } else {
            // Skip logging as we didn't reach the necessary severity level
        }
        unset($Message);
    } else {
        // Don't log ? Lets do something else here
    }
}

// Function to list projects and store in the database
function listProjectsAndStore($db) {
    $page = 1;
    $hasMore = true;

    while ($hasMore) {
        $url = "https://api.cloudflare.com/client/v4/accounts/" . CONSTANT("CLOUDFLARE_ACCOUNT_ID") . "/pages/projects?page=$page&per_page=" . CONSTANT("CLOUDFLARE_PAGINATION_LIMIT") . "";
        HHM_AuditLogSyslog(LOG_DEBUG, "hhm_cloudflare", "Making request to $url");

        // Initialize cURL session
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . CONSTANT("CLOUDFLARE_API_TOKEN") . "",
            "Content-Type: application/json"
        ]);

        // Execute the API request
        $response = curl_exec($ch);
        sleep(CONSTANT("CLOUDFLARE_API_DELAY"));
        $data = json_decode($response, true);

        // Check if the API call was successful
        if ($data && $data['success']) {
            $remainingPages = $data['result_info']['total_pages']-$page;
            foreach ($data['result'] as $project) {
                // Prepare SQL statement to insert or update the project data
                $sql = "INSERT OR REPLACE INTO cloudflare_projects (project_name, project_id, project_data) VALUES (:name, :id, :data)";

                $stmt = $db->prepare($sql);

                // Bind parameters and execute the statement
                $stmt->bindValue(':name', $project['name'], SQLITE3_TEXT);
                $stmt->bindValue(':id', $project['id'], SQLITE3_TEXT);
                $stmt->bindValue(':data', json_encode($project), SQLITE3_TEXT);
                $stmt->execute();
                HHM_AuditLogSyslog(LOG_DEBUG, "hhm_cloudflare", "Stored project $project[name]");
            }

            // Check if we've reached the last page
            $hasMore = $remainingPages;
            $page++;
        } else {
            $hasMore = false;
            HHM_AuditLogSyslog(LOG_WARNING, "hhm_cloudflare", "Failed to retrieve projects or store in the database");
        }

        // Close the cURL session
        curl_close($ch);
    }
    HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Finished listing projects and storing them in the database");
}

// Find and Delete all stale deployments
function findAllDeployments($db)
{
    // Fetch all projects
    $projectsQuery = $db->query('SELECT project_id, project_name FROM cloudflare_projects');

    while ($project = $projectsQuery->fetchArray(SQLITE3_ASSOC)) {
        $projectName = $project['project_name'];
        $page = 1;
        $hasMore = true;

        while ($hasMore) {
            $deploymentsUrl = "https://api.cloudflare.com/client/v4/accounts/" . CONSTANT("CLOUDFLARE_ACCOUNT_ID") . "/pages/projects/$projectName/deployments?page=$page&per_page=" . CONSTANT("CLOUDFLARE_PAGINATION_LIMIT") . "";
            HHM_AuditLogSyslog(LOG_DEBUG, "hhm_cloudflare", "Fetching deployments for project $projectName using deploymentUrl $deploymentsUrl");

            // Initialize cURL session for fetching deployments
            $ch = curl_init($deploymentsUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . CONSTANT("CLOUDFLARE_API_TOKEN") . "",
                "Content-Type: application/json"
            ]);

            $response = curl_exec($ch);
            sleep(CONSTANT("CLOUDFLARE_API_DELAY"));
            $data = json_decode($response, true);

            if ($data && $data['success']) {
                $remainingPages = $data['result_info']['total_pages']-$page;
                foreach ($data['result'] as $deployment) {
                    // Store each deployment in the database
                    $sql = "INSERT OR REPLACE INTO cloudflare_deployments (project_id, deployment_id, created_on, url, aliases) VALUES (:project_id, :deployment_id, :created_on, :url, :aliases)";

                    $stmt = $db->prepare($sql);

                    $stmt->bindValue(':project_id', $project['project_id'], SQLITE3_TEXT);
                    $stmt->bindValue(':deployment_id', $deployment['id'], SQLITE3_TEXT);
                    $stmt->bindValue(':created_on', $deployment['created_on'], SQLITE3_TEXT);
                    $stmt->bindValue(':url', $deployment['url'], SQLITE3_TEXT);
                    // Check if the aliases field is an array. If it is, convert it to a string before storing it.
                    if (is_array($deployment['aliases'])) {
                        $aliases = implode(',', $deployment['aliases']);
                    } else {
                        if (!array_key_exists("aliases", $deployment)) {
                            $aliases = $deployment['aliases'];
                        } else {
                            $aliases = "";
                        };
                    }
                    $stmt->bindValue(':aliases', $aliases, SQLITE3_TEXT);
                    $stmt->execute();
                    HHM_AuditLogSyslog(LOG_DEBUG, "hhm_cloudflare", "Deployment $deployment[id] has been stored successfully.");
                }
                // Check if we've reached the last page
                $hasMore = $remainingPages;
                $page++;
            } else {
                HHM_AuditLogSyslog(LOG_WARNING, "hhm_cloudflare", "Failed to retrieve deployments for project $projectName");
                $hasMore = FALSE;
            }
        }
    }
}

// Find and Delete all stale deployments
function findandDeleteStaleDeployments($db) {
    // Fetch all projects
    $projectsQuery = $db->query('SELECT project_id, project_name FROM cloudflare_projects');
    // Loop through each project to query for all deployments.
    while ($deployments = $projectsQuery->fetchArray(SQLITE3_ASSOC)) {
        $projectName = $deployments['project_name'];
        $projectId = $deployments['project_id'];
        HHM_AuditLogSyslog(LOG_DEBUG, "hhm_cloudflare", "Fetching deployments for project $projectName using projectId $projectId");
        // Query for all deployments ordering by created_on descending
        $deployments = $db->query("SELECT cd.deployment_id, cd.project_id, cd.created_on, cd.url, cd.aliases, cp.project_name FROM cloudflare_deployments AS cd JOIN cloudflare_projects AS cp ON cd.project_id = cp.project_id WHERE cp.project_id='" . $projectId . "' ORDER BY cd.created_on DESC;");
        $numRows = $db->querySingle("SELECT COUNT(*) AS Count FROM cloudflare_deployments AS cd JOIN cloudflare_projects AS cp ON cd.project_id = cp.project_id WHERE cp.project_id='" . $projectId . "' ORDER BY cd.created_on DESC;");
        // Display how many deployments we retrieved
        HHM_AuditLogSyslog(LOG_DEBUG, "hhm_cloudflare", "We found ". $numRows. " deployments for project $projectName.");
        // Loop through each deployment
        $index = 0;
        while ($deployment = $deployments->fetchArray(SQLITE3_ASSOC)) {
            // Check if this is the first, second, next to last, or last deployment for the project
            if ($index === 0 || $index === 1 || $index === $numRows - 2 || $index === $numRows - 1) {
                HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Skipping deployment $deployment[deployment_id] for project $projectName due to index [$index].");
                $index++;
                continue;
            }
            // Delete the deployment if it meets the criteria
            $deleteUrl = "https://api.cloudflare.com/client/v4/accounts/" . CONSTANT("CLOUDFLARE_ACCOUNT_ID") . "/pages/projects/$projectName/deployments/$deployment[deployment_id]";

            if (CONSTANT("CLOUDFLARE_DEBUG")) {
                // Debug mode is on, so just print the deployment ID to be deleted
                HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Debug: Would delete deployment $deployment[deployment_id] of project $projectName using URL $deleteUrl");
            } else {
                HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Deleting deployment $deployment[deployment_id] of project $projectName");
                // Initialize cURL session for deleting a deployment
                $chDelete = curl_init($deleteUrl);
                curl_setopt($chDelete, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chDelete, CURLOPT_CUSTOMREQUEST, "DELETE");
                curl_setopt($chDelete, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer " . CONSTANT("CLOUDFLARE_API_TOKEN") . "",
                    "Content-Type: application/json"
                ]);

                $deleteResponse = curl_exec($chDelete);
                sleep(CONSTANT("CLOUDFLARE_API_DELAY"));
                $deleteData = json_decode($deleteResponse, true);

                if ($deleteData && $deleteData['success']) {
                    HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Successfully deleted deployment $deployment[deployment_id] from project $projectName");
                } else {
                    HHM_AuditLogSyslog(LOG_WARNING, "hhm_cloudflare", "Failed to delete deployment $deployment[deployment_id] from project $projectName");
                }
                curl_close($chDelete);

                $sql = "DELETE FROM cloudflare_deployments WHERE deployment_id = :deployment_id";
                $stmt = $db->prepare($sql);
                $stmt->bindValue(':deployment_id', $deployment['deployment_id'], SQLITE3_TEXT);
                $stmt->execute();
                HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Deleted deployment $deployment[deployment_id] for project $projectName from database.");
            }
            $index++;
        }
    }
}

HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Starting application.");

// Does the database already exist?
if (!file_exists($dbFile)) {
    HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Database does not exist, creating it.");
    // Create/Open the SQLite database
    $db = new SQLite3($dbFile);
    // Create the table structure
    $sqlCreateTable = "CREATE TABLE IF NOT EXISTS cloudflare_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, project_name TEXT NOT NULL, project_id TEXT NOT NULL UNIQUE, project_data TEXT)";
    $db->exec($sqlCreateTable);
    // Create the table structure
    $sqlCreateTable = "CREATE TABLE IF NOT EXISTS cloudflare_deployments (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id TEXT NOT NULL, deployment_id TEXT NOT NULL, created_on TEXT NOT NULL, url TEXT NOT NULL, aliases TEXT NOT NULL)";
    $db->exec($sqlCreateTable);
} else {
    HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Database already exists, opening it.");
    // Set up the database connection
    $db = new SQLite3($dbFile);
    // Check if the "cloudflare_projects" table exists
    $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name='cloudflare_projects'";
    $result = $db->query($sql);
    if ($result->fetchArray(SQLITE3_NUM) === false) {
        HHM_AuditLogSyslog(LOG_WARNING, "hhm_cloudflare", "The 'cloudflare_projects' table does not exist. Creating It.");
        $sqlCreateTable = "CREATE TABLE IF NOT EXISTS cloudflare_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, project_name TEXT NOT NULL, project_id TEXT NOT NULL UNIQUE, project_data TEXT)";
        $db->exec($sqlCreateTable);
    } else {
        HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Deleting all records from the 'cloudflare_projects' table.");
        // Delete all records from the "cloudflare_projects" table
        $sql = "DELETE FROM cloudflare_projects";
        $db->exec($sql);
    }

    // Check if the "cloudflare_deployments" table exists
    $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name='cloudflare_deployments'";
    $result = $db->query($sql);
    if ($result->fetchArray(SQLITE3_NUM) === false) {
        HHM_AuditLogSyslog(LOG_WARNING, "hhm_cloudflare", "The 'cloudflare_deployments' table does not exist. Creating It.");
        // Create the table structure
        $sqlCreateTable = "CREATE TABLE IF NOT EXISTS cloudflare_deployments (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id TEXT NOT NULL, deployment_id TEXT NOT NULL, created_on TEXT NOT NULL, url TEXT NOT NULL, aliases TEXT NOT NULL)";
        $db->exec($sqlCreateTable);
    } else {
        HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Deleting all records from the 'cloudflare_deployments' table.");
        // Delete all records from the "cloudflare_deployments" table
        $sql = "DELETE FROM cloudflare_deployments";
        $db->exec($sql);
    }
}

// Call the function to list projects and store them in the database
listProjectsAndStore($db);

// Call the function to find and delete stale deployments
findAllDeployments($db);

// Find and delete stale deployments
findandDeleteStaleDeployments($db);

HHM_AuditLogSyslog(LOG_INFO, "hhm_cloudflare", "Finished listing projects and storing them in the database.");

$db->close();

?>
