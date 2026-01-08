const fs = require("fs");
const path = require("path");

const newVersion = process.argv[2];
if (!newVersion) {
    console.error("No version provided.");
    process.exit(1);
  }
function updateChartYamlVersion(newVersion) {
    const chartFilePath = path.resolve(__dirname, "chart/cohort-api/Chart.yaml");
    const chartContent = fs.readFileSync(chartFilePath, "utf8");

    const updatedContent = chartContent.replace(
      /^appVersion:\s*"?[0-9]+\.[0-9]+\.[0-9]+"?/m,
      `appVersion: "${newVersion}"`
    );


    fs.writeFileSync(chartFilePath, updatedContent, "utf8");
    console.log(`Updated chart/Chart.yaml to version ${newVersion}`);
  }
function updatePackageJsonVersion(newVersion) {
  const packageJsonPath = path.resolve(__dirname, "package.json");
  const packageJsonContent = fs.readFileSync(packageJsonPath, "utf8");

  const packageJson = JSON.parse(packageJsonContent);
  packageJson.version = newVersion; 

  fs.writeFileSync(packageJsonPath, JSON.stringify(packageJson, null, 2), "utf8");
  console.log(`Updated package.json to version ${newVersion}`);
}



updateChartYamlVersion(newVersion);
updatePackageJsonVersion(newVersion);