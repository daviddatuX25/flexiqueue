package ph.edu.ispsc.flexiqueue;

import org.junit.Before;
import org.junit.Test;

import java.io.File;
import java.nio.file.Files;

import static org.junit.Assert.*;

public class AndroidManifestTest {

    private String manifestContent;

    @Before
    public void setUp() throws Exception {
        File manifest = findManifest();
        assertNotNull("AndroidManifest.xml must be found — checked CWD and parent directories. "
            + "Current user.dir: " + System.getProperty("user.dir"), manifest);
        assertTrue("AndroidManifest.xml must exist", manifest.exists());
        manifestContent = new String(Files.readAllBytes(manifest.toPath()));
    }

    /**
     * Robustly locates AndroidManifest.xml regardless of CWD.
     *
     * Gradle runs unit tests from the {@code android/} module directory, so
     * {@code app/src/main/AndroidManifest.xml} is the expected relative path.
     * IDEs or Windows CI runners may set CWD to the project root instead,
     * in which case the path is {@code android/app/src/main/AndroidManifest.xml}.
     *
     * This helper tries both relative paths from CWD first, then walks up the
     * directory tree looking for either layout.
     */
    private File findManifest() {
        String[] relativePaths = {
            "app/src/main/AndroidManifest.xml",          // CWD = android/
            "android/app/src/main/AndroidManifest.xml",   // CWD = project root
        };

        // Try from CWD first
        for (String relPath : relativePaths) {
            File candidate = new File(relPath);
            if (candidate.exists()) return candidate;
        }

        // Walk up the directory tree looking for either layout
        File dir = new File(System.getProperty("user.dir"));
        while (dir != null) {
            for (String relPath : relativePaths) {
                File candidate = new File(dir, relPath);
                if (candidate.exists()) return candidate;
            }
            dir = dir.getParentFile();
        }

        return null;
    }

    @Test
    public void manifest_hasAllRequiredPermissions() {
        String[] requiredPermissions = {
            "android.permission.INTERNET",
            "android.permission.FOREGROUND_SERVICE",
            "android.permission.FOREGROUND_SERVICE_SPECIAL_USE",
            "android.permission.WAKE_LOCK",
            "android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS",
            "com.termux.permission.RUN_COMMAND"
        };
        for (String perm : requiredPermissions) {
            assertTrue("Missing permission: " + perm,
                manifestContent.contains("android:name=\"" + perm + "\""));
        }
    }

    @Test
    public void manifest_declaresPowerGuardServiceWithSpecialUse() {
        assertTrue("Must declare PowerGuardService",
            manifestContent.contains("android:name=\".PowerGuardService\""));
        assertTrue("Must have foregroundServiceType=\"specialUse\"",
            manifestContent.contains("android:foregroundServiceType=\"specialUse\""));
        assertTrue("Must have PROPERTY_SPECIAL_USE_FGS_SUBTYPE",
            manifestContent.contains("android.app.PROPERTY_SPECIAL_USE_FGS_SUBTYPE"));
    }

    @Test
    public void manifest_declaresBootReceiverWithBootCompleted() {
        assertTrue("Must declare BootReceiver",
            manifestContent.contains("android:name=\".BootReceiver\""));
        assertTrue("Must listen for BOOT_COMPLETED",
            manifestContent.contains("android.intent.action.BOOT_COMPLETED"));
    }
}
