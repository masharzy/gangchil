package com.gangchil.counter;
import android.app.Application;
public class GangchilApp extends Application {
 @Override public void onCreate(){super.onCreate();NativePush.initialize(this);}
}
