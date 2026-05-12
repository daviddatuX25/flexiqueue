package ph.edu.ispsc.flexiqueue;

import android.content.Intent;

import com.getcapacitor.JSObject;
import com.getcapacitor.PluginCall;

import org.junit.After;
import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.mockito.ArgumentCaptor;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.RuntimeEnvironment;
import org.robolectric.Shadows;
import org.robolectric.annotation.Config;
import org.robolectric.shadows.ShadowApplication;

import java.io.File;

import static org.junit.Assert.*;
import static org.mockito.Mockito.*;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 28)
public class EdgeBundlePluginTest {

    private EdgeBundlePlugin plugin;
    private static final String TARGET_DIR = EdgePaths.EDGE_DIR;
    private static final String MARKER_FILE = EdgePaths.MARKER_FILE;

    @Before
    public void setUp() {
        plugin = spy(new EdgeBundlePlugin());
        doReturn(RuntimeEnvironment.application).when(plugin).getContext();
    }

    @After
    public void tearDown() {
        cleanupMarkerDir();
    }

    private void cleanupMarkerDir() {
        File marker = new File(TARGET_DIR, MARKER_FILE);
        if (marker.exists()) marker.delete();
        File parent = marker.getParentFile();
        if (parent != null && parent.exists()) {
            parent.delete();
        }
    }

    @Test
    public void extractEdgeBundle_alreadyExtracted_resolvesWithAlreadyExtractedTrue() {
        File marker = new File(TARGET_DIR, MARKER_FILE);
        marker.getParentFile().mkdirs();
        try { marker.createNewFile(); } catch (Exception ignored) {}

        PluginCall call = mock(PluginCall.class);
        plugin.extractEdgeBundle(call);

        ArgumentCaptor<JSObject> captor = ArgumentCaptor.forClass(JSObject.class);
        verify(call).resolve(captor.capture());
        assertTrue(captor.getValue().getBool("alreadyExtracted"));
    }

    @Test
    public void extractEdgeBundle_notExtracted_assetMissing_rejects() {
        File marker = new File(TARGET_DIR, MARKER_FILE);
        marker.getParentFile().mkdirs();
        marker.delete();

        PluginCall call = mock(PluginCall.class);
        plugin.extractEdgeBundle(call);

        verify(call).reject(eq("EXTRACTION_FAILED"), anyString());
    }

    @Test
    public void startEdgeStack_resolvesWithStarted() {
        PluginCall call = mock(PluginCall.class);
        plugin.startEdgeStack(call);

        ArgumentCaptor<JSObject> captor = ArgumentCaptor.forClass(JSObject.class);
        verify(call).resolve(captor.capture());
        assertTrue(captor.getValue().getBool("started"));
    }

    @Test
    public void startEdgeStack_usesSetClassName_notSendBroadcast() {
        PluginCall call = mock(PluginCall.class);
        plugin.startEdgeStack(call);

        ShadowApplication shadowApp = Shadows.shadowOf(RuntimeEnvironment.application);
        Intent serviceIntent = shadowApp.getNextStartedService();
        assertNotNull("startEdgeStack should start a service", serviceIntent);
        assertNotNull("Should use setClassName (not sendBroadcast)",
            serviceIntent.getComponent());
        assertEquals("com.termux", serviceIntent.getComponent().getPackageName());
        assertEquals("com.termux.app.RunCommandService",
            serviceIntent.getComponent().getClassName());
    }

    @Test
    public void stopEdgeStack_resolvesWithStopped() {
        PluginCall call = mock(PluginCall.class);
        plugin.stopEdgeStack(call);

        ArgumentCaptor<JSObject> captor = ArgumentCaptor.forClass(JSObject.class);
        verify(call).resolve(captor.capture());
        assertTrue(captor.getValue().getBool("stopped"));
    }

    @Test
    public void startEdgeStack_usesCorrectRunCommandPath() {
        PluginCall call = mock(PluginCall.class);
        plugin.startEdgeStack(call);

        ShadowApplication shadowApp = Shadows.shadowOf(RuntimeEnvironment.application);
        Intent serviceIntent = shadowApp.getNextStartedService();
        assertNotNull(serviceIntent);
        String path = serviceIntent.getStringExtra("com.termux.RUN_COMMAND_PATH");
        assertTrue("Should point to start-stack.sh",
            path.endsWith("/scripts/edge/setup/phone/start-stack.sh"));
    }

    @Test
    public void stopEdgeStack_usesStopStackScript() {
        PluginCall call = mock(PluginCall.class);
        plugin.stopEdgeStack(call);

        ShadowApplication shadowApp = Shadows.shadowOf(RuntimeEnvironment.application);
        Intent serviceIntent = shadowApp.getNextStartedService();
        assertNotNull(serviceIntent);
        String path = serviceIntent.getStringExtra("com.termux.RUN_COMMAND_PATH");
        assertTrue("Should point to stop-stack.sh instead of pkill",
            path.endsWith("/scripts/edge/setup/phone/stop-stack.sh"));
    }
}