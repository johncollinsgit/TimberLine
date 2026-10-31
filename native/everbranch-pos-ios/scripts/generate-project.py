#!/usr/bin/env python3
"""Generate the dependency-free iPhone project; no signing team or entitlement is selected."""
from pathlib import Path
import hashlib
import json

base = Path(__file__).resolve().parent.parent
project = base / "EverbranchPOS.xcodeproj"
project.mkdir(exist_ok=True)
objects = []
def key(name): return hashlib.sha256(name.encode()).hexdigest()[:24].upper()
def add(name, body):
    objects.append(f"{key(name)} = {{{body}}};")
    return key(name)
def array(items): return "(" + ",".join(items) + (",)" if items else ")")

app_files = sorted(str(path.relative_to(base)) for folder in [base / "App", base / "Sources"]
                   for path in folder.rglob("*.swift"))
test_files = sorted(str(path.relative_to(base)) for path in (base / "UITests").glob("*.swift"))
refs = []
def sources(files, prefix):
    builds = []
    for path in files:
        ref = add("file:" + path, f'isa = PBXFileReference; lastKnownFileType = sourcecode.swift; path = "{path}"; sourceTree = "<group>";')
        refs.append(ref)
        builds.append(add("build:" + path, f"isa = PBXBuildFile; fileRef = {ref};"))
    return add(prefix + "Sources", f"isa = PBXSourcesBuildPhase; buildActionMask = 2147483647; files = {array(builds)}; runOnlyForDeploymentPostprocessing = 0;")
app_sources = sources(app_files, "App")
test_sources = sources(test_files, "Test")
resource_ref = add("RegisterDemo", 'isa = PBXFileReference; lastKnownFileType = folder; path = "App/RegisterDemo"; sourceTree = "<group>";')
refs.append(resource_ref)
resource_build = add("RegisterDemoBuild", f"isa = PBXBuildFile; fileRef = {resource_ref};")
icon_ref = add("MerchantAssets", 'isa = PBXFileReference; lastKnownFileType = folder.assetcatalog; path = "App/Assets.xcassets"; sourceTree = "<group>";')
refs.append(icon_ref)
icon_build = add("MerchantAssetsBuild", f"isa = PBXBuildFile; fileRef = {icon_ref};")
resources = add("Resources", f"isa = PBXResourcesBuildPhase; buildActionMask = 2147483647; files = {array([resource_build, icon_build])}; runOnlyForDeploymentPostprocessing = 0;")
package_ref = add("SquarePackage", 'isa = XCRemoteSwiftPackageReference; repositoryURL = "https://github.com/square/mobile-payments-sdk-ios"; requirement = {kind = exactVersion; version = 2.7.0;};')
package_product = add("SquareProduct", f'isa = XCSwiftPackageProductDependency; package = {package_ref}; productName = SquareMobilePaymentsSDK;')
package_build = add("SquareBuild", f'isa = PBXBuildFile; productRef = {package_product};')
frameworks = add("Frameworks", f"isa = PBXFrameworksBuildPhase; buildActionMask = 2147483647; files = {array([package_build])}; runOnlyForDeploymentPostprocessing = 0;")
setup_script = 'if [ "$CODE_SIGNING_ALLOWED" = "NO" ]; then export EXPANDED_CODE_SIGN_IDENTITY=-; fi\nSETUP_SCRIPT="${BUILT_PRODUCTS_DIR}/${FRAMEWORKS_FOLDER_PATH}/SquareMobilePaymentsSDK.framework/setup"\nif [ -f "$SETUP_SCRIPT" ]; then "$SETUP_SCRIPT"; fi'
setup_phase = add("SquareSetup", f'isa = PBXShellScriptBuildPhase; buildActionMask = 2147483647; files = (); inputPaths = (); outputPaths = (); runOnlyForDeploymentPostprocessing = 0; shellPath = "/bin/sh"; shellScript = {json.dumps(setup_script)};')
app_product = add("AppProduct", 'isa = PBXFileReference; explicitFileType = wrapper.application; path = EverbranchPOS.app; sourceTree = BUILT_PRODUCTS_DIR;')
test_product = add("TestProduct", 'isa = PBXFileReference; explicitFileType = wrapper.cfbundle; path = EverbranchPOSUITests.xctest; sourceTree = BUILT_PRODUCTS_DIR;')
products = add("Products", f'isa = PBXGroup; children = {array([app_product, test_product])}; name = Products; sourceTree = "<group>";')
group = add("MainGroup", f'isa = PBXGroup; children = {array(refs + [products])}; sourceTree = "<group>";')
common = 'CLANG_ENABLE_MODULES = YES; SDKROOT = iphoneos; IPHONEOS_DEPLOYMENT_TARGET = 17.1; SWIFT_VERSION = 5.0; TARGETED_DEVICE_FAMILY = 1; SUPPORTED_PLATFORMS = "iphoneos iphonesimulator"; CODE_SIGN_STYLE = Automatic; PRODUCT_NAME = "$(TARGET_NAME)";'
def configs(prefix, settings):
    keys = []
    for name, optimization in [("Debug", 'SWIFT_OPTIMIZATION_LEVEL = "-Onone"; SWIFT_ACTIVE_COMPILATION_CONDITIONS = DEBUG;'), ("Release", 'SWIFT_OPTIMIZATION_LEVEL = "-O";')]:
        keys.append(add(prefix + name, f'isa = XCBuildConfiguration; buildSettings = {{{common} {optimization} {settings}}}; name = {name};'))
    return add(prefix + "ConfigList", f"isa = XCConfigurationList; buildConfigurations = {array(keys)}; defaultConfigurationIsVisible = 0; defaultConfigurationName = Release;")
app_configs = configs("App", 'ASSETCATALOG_COMPILER_APPICON_NAME = AppIcon; LD_RUNPATH_SEARCH_PATHS = "$(inherited) @executable_path/Frameworks"; PRODUCT_BUNDLE_IDENTIFIER = com.everbranch.pos; CURRENT_PROJECT_VERSION = 1; INFOPLIST_FILE = App/Info.plist; CODE_SIGN_ENTITLEMENTS = App/EverbranchPOS.entitlements; POS_API_ORIGIN = "https://app.theeverbranch.com"; POS_SQUARE_APPLICATION_ID = ""; POS_PAYMENT_ENVIRONMENT = sandbox; ENABLE_USER_SCRIPT_SANDBOXING = NO;')
test_configs = configs("Test", 'PRODUCT_BUNDLE_IDENTIFIER = com.everbranch.pos.uitests; GENERATE_INFOPLIST_FILE = YES; TEST_TARGET_NAME = EverbranchPOS;')
project_configs = configs("Project", "")
app_target = add("AppTarget", f'isa = PBXNativeTarget; buildConfigurationList = {app_configs}; buildPhases = {array([app_sources, frameworks, resources, setup_phase])}; buildRules = (); dependencies = (); packageProductDependencies = {array([package_product])}; name = EverbranchPOS; productName = EverbranchPOS; productReference = {app_product}; productType = "com.apple.product-type.application";')
proxy = add("AppProxy", f'isa = PBXContainerItemProxy; containerPortal = {key("Project")}; proxyType = 1; remoteGlobalIDString = {app_target}; remoteInfo = EverbranchPOS;')
dependency = add("AppDependency", f"isa = PBXTargetDependency; target = {app_target}; targetProxy = {proxy};")
test_target = add("TestTarget", f'isa = PBXNativeTarget; buildConfigurationList = {test_configs}; buildPhases = {array([test_sources])}; buildRules = (); dependencies = {array([dependency])}; name = EverbranchPOSUITests; productName = EverbranchPOSUITests; productReference = {test_product}; productType = "com.apple.product-type.bundle.ui-testing";')
root = add("Project", f'isa = PBXProject; attributes = {{LastUpgradeCheck = 2630; TargetAttributes = {{{test_target} = {{TestTargetID = {app_target};}};}};}}; buildConfigurationList = {project_configs}; compatibilityVersion = "Xcode 14.0"; developmentRegion = en; hasScannedForEncodings = 0; knownRegions = (en, Base); mainGroup = {group}; productRefGroup = {products}; projectDirPath = ""; projectRoot = ""; packageReferences = {array([package_ref])}; targets = {array([app_target, test_target])};')
(project / "project.pbxproj").write_text('// !$*UTF8*$!\n{ archiveVersion = 1; classes = {}; objectVersion = 56; objects = {\n' + '\n'.join(objects) + f'\n}}; rootObject = {root}; }}\n')
scheme_dir = project / "xcshareddata/xcschemes"
scheme_dir.mkdir(parents=True, exist_ok=True)
def buildable(identifier, name, product):
    return f'<BuildableReference BuildableIdentifier="primary" BlueprintIdentifier="{identifier}" BuildableName="{product}" BlueprintName="{name}" ReferencedContainer="container:EverbranchPOS.xcodeproj"/>'
app_ref = buildable(app_target, "EverbranchPOS", "EverbranchPOS.app")
test_ref = buildable(test_target, "EverbranchPOSUITests", "EverbranchPOSUITests.xctest")
(scheme_dir / "EverbranchPOS.xcscheme").write_text(f'''<?xml version="1.0" encoding="UTF-8"?>
<Scheme LastUpgradeVersion="2630" version="1.3">
 <BuildAction parallelizeBuildables="YES" buildImplicitDependencies="YES"><BuildActionEntries><BuildActionEntry buildForTesting="YES" buildForRunning="YES" buildForProfiling="YES" buildForArchiving="YES" buildForAnalyzing="YES">{app_ref}</BuildActionEntry></BuildActionEntries></BuildAction>
 <TestAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" shouldUseLaunchSchemeArgsEnv="YES"><Testables><TestableReference skipped="NO">{test_ref}</TestableReference></Testables></TestAction>
 <LaunchAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" launchStyle="0" useCustomWorkingDirectory="NO" ignoresPersistentStateOnLaunch="NO" debugDocumentVersioning="YES"><BuildableProductRunnable runnableDebuggingMode="0">{app_ref}</BuildableProductRunnable></LaunchAction>
 <ProfileAction buildConfiguration="Release" shouldUseLaunchSchemeArgsEnv="YES" savedToolIdentifier="" useCustomWorkingDirectory="NO" debugDocumentVersioning="YES"><BuildableProductRunnable runnableDebuggingMode="0">{app_ref}</BuildableProductRunnable></ProfileAction>
 <AnalyzeAction buildConfiguration="Debug"/><ArchiveAction buildConfiguration="Release" revealArchiveInOrganizer="YES"/>
</Scheme>\n''')
print("Generated EverbranchPOS.xcodeproj (no selected signing team).")
