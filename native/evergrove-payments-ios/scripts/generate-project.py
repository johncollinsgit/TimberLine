#!/usr/bin/env python3
"""Generate the dependency-free iPhone project; no signing team or entitlement is selected."""
from pathlib import Path
import hashlib

base = Path(__file__).resolve().parent.parent
project = base / "EvergrovePayments.xcodeproj"
project.mkdir(exist_ok=True)
objects = []
def key(name): return hashlib.sha256(name.encode()).hexdigest()[:24].upper()
def add(name, body):
    objects.append(f"{key(name)} = {{{body}}};")
    return key(name)
def array(items): return "(" + ",".join(items) + ",)"

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
resources = add("Resources", f"isa = PBXResourcesBuildPhase; buildActionMask = 2147483647; files = {array([resource_build])}; runOnlyForDeploymentPostprocessing = 0;")
frameworks = add("Frameworks", "isa = PBXFrameworksBuildPhase; buildActionMask = 2147483647; files = (); runOnlyForDeploymentPostprocessing = 0;")
app_product = add("AppProduct", 'isa = PBXFileReference; explicitFileType = wrapper.application; path = EvergrovePayments.app; sourceTree = BUILT_PRODUCTS_DIR;')
test_product = add("TestProduct", 'isa = PBXFileReference; explicitFileType = wrapper.cfbundle; path = EvergrovePaymentsUITests.xctest; sourceTree = BUILT_PRODUCTS_DIR;')
products = add("Products", f'isa = PBXGroup; children = {array([app_product, test_product])}; name = Products; sourceTree = "<group>";')
group = add("MainGroup", f'isa = PBXGroup; children = {array(refs + [products])}; sourceTree = "<group>";')
common = 'CLANG_ENABLE_MODULES = YES; SDKROOT = iphoneos; IPHONEOS_DEPLOYMENT_TARGET = 17.0; SWIFT_VERSION = 6.0; TARGETED_DEVICE_FAMILY = 1; SUPPORTED_PLATFORMS = "iphoneos iphonesimulator"; CODE_SIGN_STYLE = Automatic; PRODUCT_NAME = "$(TARGET_NAME)";'
def configs(prefix, settings):
    keys = []
    for name, optimization in [("Debug", 'SWIFT_OPTIMIZATION_LEVEL = "-Onone"; SWIFT_ACTIVE_COMPILATION_CONDITIONS = DEBUG;'), ("Release", 'SWIFT_OPTIMIZATION_LEVEL = "-O";')]:
        keys.append(add(prefix + name, f'isa = XCBuildConfiguration; buildSettings = {{{common} {optimization} {settings}}}; name = {name};'))
    return add(prefix + "ConfigList", f"isa = XCConfigurationList; buildConfigurations = {array(keys)}; defaultConfigurationIsVisible = 0; defaultConfigurationName = Release;")
app_configs = configs("App", 'PRODUCT_BUNDLE_IDENTIFIER = com.evergrove.payments.rehearsal; INFOPLIST_FILE = App/Info.plist;')
test_configs = configs("Test", 'PRODUCT_BUNDLE_IDENTIFIER = com.evergrove.payments.rehearsal.uitests; GENERATE_INFOPLIST_FILE = YES; TEST_TARGET_NAME = EvergrovePayments;')
project_configs = configs("Project", "")
app_target = add("AppTarget", f'isa = PBXNativeTarget; buildConfigurationList = {app_configs}; buildPhases = {array([app_sources, frameworks, resources])}; buildRules = (); dependencies = (); name = EvergrovePayments; productName = EvergrovePayments; productReference = {app_product}; productType = "com.apple.product-type.application";')
proxy = add("AppProxy", f'isa = PBXContainerItemProxy; containerPortal = {key("Project")}; proxyType = 1; remoteGlobalIDString = {app_target}; remoteInfo = EvergrovePayments;')
dependency = add("AppDependency", f"isa = PBXTargetDependency; target = {app_target}; targetProxy = {proxy};")
test_target = add("TestTarget", f'isa = PBXNativeTarget; buildConfigurationList = {test_configs}; buildPhases = {array([test_sources])}; buildRules = (); dependencies = {array([dependency])}; name = EvergrovePaymentsUITests; productName = EvergrovePaymentsUITests; productReference = {test_product}; productType = "com.apple.product-type.bundle.ui-testing";')
root = add("Project", f'isa = PBXProject; attributes = {{LastUpgradeCheck = 2630; TargetAttributes = {{{test_target} = {{TestTargetID = {app_target};}};}};}}; buildConfigurationList = {project_configs}; compatibilityVersion = "Xcode 14.0"; developmentRegion = en; hasScannedForEncodings = 0; knownRegions = (en, Base); mainGroup = {group}; productRefGroup = {products}; projectDirPath = ""; projectRoot = ""; targets = {array([app_target, test_target])};')
(project / "project.pbxproj").write_text('// !$*UTF8*$!\n{ archiveVersion = 1; classes = {}; objectVersion = 56; objects = {\n' + '\n'.join(objects) + f'\n}}; rootObject = {root}; }}\n')
scheme_dir = project / "xcshareddata/xcschemes"
scheme_dir.mkdir(parents=True, exist_ok=True)
def buildable(identifier, name, product):
    return f'<BuildableReference BuildableIdentifier="primary" BlueprintIdentifier="{identifier}" BuildableName="{product}" BlueprintName="{name}" ReferencedContainer="container:EvergrovePayments.xcodeproj"/>'
app_ref = buildable(app_target, "EvergrovePayments", "EvergrovePayments.app")
test_ref = buildable(test_target, "EvergrovePaymentsUITests", "EvergrovePaymentsUITests.xctest")
(scheme_dir / "EvergrovePayments.xcscheme").write_text(f'''<?xml version="1.0" encoding="UTF-8"?>
<Scheme LastUpgradeVersion="2630" version="1.3">
 <BuildAction parallelizeBuildables="YES" buildImplicitDependencies="YES"><BuildActionEntries><BuildActionEntry buildForTesting="YES" buildForRunning="YES" buildForProfiling="YES" buildForArchiving="YES" buildForAnalyzing="YES">{app_ref}</BuildActionEntry></BuildActionEntries></BuildAction>
 <TestAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" shouldUseLaunchSchemeArgsEnv="YES"><Testables><TestableReference skipped="NO">{test_ref}</TestableReference></Testables></TestAction>
 <LaunchAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" launchStyle="0" useCustomWorkingDirectory="NO" ignoresPersistentStateOnLaunch="NO" debugDocumentVersioning="YES"><BuildableProductRunnable runnableDebuggingMode="0">{app_ref}</BuildableProductRunnable></LaunchAction>
 <ProfileAction buildConfiguration="Release" shouldUseLaunchSchemeArgsEnv="YES" savedToolIdentifier="" useCustomWorkingDirectory="NO" debugDocumentVersioning="YES"><BuildableProductRunnable runnableDebuggingMode="0">{app_ref}</BuildableProductRunnable></ProfileAction>
 <AnalyzeAction buildConfiguration="Debug"/><ArchiveAction buildConfiguration="Release" revealArchiveInOrganizer="YES"/>
</Scheme>\n''')
print("Generated EvergrovePayments.xcodeproj (no selected signing team).")
